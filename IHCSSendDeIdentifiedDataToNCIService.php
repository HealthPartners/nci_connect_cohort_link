<?php

namespace HealthPartners\Institute\NCIConnectCohortLink\Service;

use Exception;
use REDCap;

class IHCSSendDeIdentifiedDataToNCIService
{
    //To Store Primay External Module Object
    private $module;

    private $nci_connect_api_key_file_loc; // To hold the private key file to generate access token
    private $nci_connect_api_key; // To hold NCI API key
    private $nci_connect_api_endpoint; // To hold NCI API endpoint
    private $inputstudyid; // To hold input study id field
    private $record_filter_logic; // to hold record filter condition which helps to only include valid studyids for api request
    private $batch_size; // to hold number of records send part of each API request
    private $email_alert_from; // to hold from email address for email alert when error occurred
    private $email_alert_to; // to hold comma sepeated to email address for email alert when error occurred
    private $curr_proj_record_id_field;
    private $fields_send_with_token_request; // to hold list of field(s) send part of API request - array.
    private $ncitoken;
    private $deidentified_data_send_field_list; // to hold list of field(s) from EM config
    private $deidentified_data_sent_status;

    private $optout_reason_field_list; // used to send different parent strcture for optout reasons

    private $is_preconsent_optout_stat_sent; // to hold the status of preconsent optout status if available part of transfer
    private $is_max_preconsent_con_reach; //to hold the status of maximum preconsent contact reach status

    //utlize this service to send identity verification table since its similar de-identified data
    private $is_for_iv_table;
    private $iv_table_data_send_field_list; // to hold list of field(s) from EM config
    private $iv_table_data_sent_status;
    private $iv_status_api_endpoint; // used to store identifyParticipant for manual veirfication participants
    private $consistencycheck_endpoint; // used to store consistencyCheck QC endpoint
    private $updateParticipantData_api_endpoint; // used to send preconset and max outreach
    private $iv_status_api_sent_success; // used to store final IV status sent for write back to record
    private $iv_status_api_sent_success_msg; // used to store final IV status sent for write back to record
    private $deidentified_data_sent_success; // used to store deidentified data sent success status for write back to record

    //To track Batch progress
    private $curr_batch;
    private $total_num_batch;
    private $total_num_record_processed;
    private $batch_job_id;



    const NCI_MAX_BATCH_SIZE = 1;
    const NCI_MODULE_LOG_NAME = "EM - NCI Send deidentified data service";

    public function __construct($module)
    {
        $this->module = $module;
    }

    public function startNewBatchJob()
    {
        return $this->startBatchProcess();
    }

    public function startNewBatchJobForIVTable()
    {
        $this->is_for_iv_table = true;
        return $this->startBatchProcess();
    }

    // This function used to start the batch process and manage pre & post batch job tasks
    private function startBatchProcess()
    {
        $this->batch_job_id = uniqid("batch", true);
        $this->iniGenerator();
        $redcap_conceptid_list = array();
        $redcap_conceptid_map = array();
        $recordStudyIdTokenMapArray = array();
        $redcap_data_with_record_id = array();
        $redcap_data = array();
        $data = array(); // used to send through API
        $this->module->log("Send Deidentified batch job started for Batch ID::" . $this->batch_job_id);


        //get all the list of data items from REDCap
        if (!isset($this->fields_send_with_token_request)) {
            $this->fields_send_with_token_request = array();
            $redcap_conceptid_list = explode(',',  $this->deidentified_data_send_field_list);
            foreach ($redcap_conceptid_list as $item) {
                $tempmap = explode("=", $item); // format : redcap_var_name=conceptid
                $redcap_conceptid_map[$tempmap[0]] = $tempmap[1];
                array_push($this->fields_send_with_token_request, $tempmap[0]);
            }
        }

        if (isset($this->inputstudyid) && $this->inputstudyid == REDCap::getRecordIdField()) {
            array_push($this->fields_send_with_token_request, $this->inputstudyid);
        } else {
            array_push($this->fields_send_with_token_request, REDCap::getRecordIdField());
            array_push($this->fields_send_with_token_request, $this->inputstudyid);
        }

        //add nci_token to list of fields to extract from REDCap
        array_push($this->fields_send_with_token_request, $this->ncitoken);

        //temp testing the outbound request body
        $this->module->log("record filter logic: $this->record_filter_logic", [
            'batch_job_id' => $this->batch_job_id
        ]);

        $this->module->log("fields send :" . json_encode($this->fields_send_with_token_request) . "## " . $this->record_filter_logic . "### " . $this->module->getProjectId(), [
            'batch_job_id' => $this->batch_job_id
        ]);

        if ($this->record_filter_logic != "") {
            $this->module->log("Before Calling REDCap::getData::fields" . print_r($this->fields_send_with_token_request, true));
            //print_r(REDCap::getData($this->module->getProjectId() , 'array', NULL, $this->fields_send_with_token_request, NULL, NULL, false, false, false, $this->record_filter_logic, false, false));
            $redcap_data_with_record_id = REDCap::getData($this->module->getProjectId(), 'array', NULL, $this->fields_send_with_token_request, NULL, NULL, false, false, false, $this->record_filter_logic, false, false);

            $this->module->log("REDCap record with Data send : " . count($redcap_data_with_record_id));

            //echo "inside record filter logic" ;
            //echo json_encode($redcap_data_with_record_id);
            //echo "done ####"		;
        }

        #$this->module->log("REDCap record with Data send : " . json_encode( $redcap_data_with_record_id ) , [ 'batch_job_id' => $this->batch_job_id  ]);

        //echo "after comments" ;
        //to handle optout reason data send with differnt scanario, prepare lookup array and check if part of opt-out 
        $listOfOptOutReason = array();
        if (isset($this->optout_reason_field_list) && !empty($this->optout_reason_field_list)) {
            $listOfOptOutReason = explode(",", $this->optout_reason_field_list);
        }

        $this->module->log("After OpoOutReason");
        //Prepare the data with concept id
        foreach ($redcap_data_with_record_id as $key => $event) {
            $tempArray = array();
            foreach ($event as $eventid => $dataitems) {
                foreach ($dataitems as $field => $val) {
                    if (array_key_exists($field, $redcap_conceptid_map)) { // to find out match concept id variable
                        if (isset($val) && strlen($val) > 0) {

                            if (in_array($redcap_conceptid_map[$field], $listOfOptOutReason)) { // part of opt-out reason
                                $tempArray["706283025"][$redcap_conceptid_map[$field]] = $val;
                            } else {
                                //Do not send studyId as per new API Change 03/11/2023 
                                if ($field != REDCap::getRecordIdField()) {
                                    $tempArray[$redcap_conceptid_map[$field]] = $val;
                                }
                            }
                            //to check preconsent optout field or not
                            if ($field == "preconsent_optout") { // TODO with config
                                $this->is_preconsent_optout_stat_sent = 1;
                            }
                            if ($field == "rec_max_contact_reached") { // TODO with config
                                $this->is_max_preconsent_con_reach = 1;
                            }
                        }
                    } else {
                        //$data [$field] = $val;
                    }
                }
                // recordStudyIdTokenMapArray will used to write data back to REDCap record
                $recordStudyIdTokenMapArray[$dataitems[$this->ncitoken]]   = $dataitems[$this->curr_proj_record_id_field];
            }
            #$this->module->log("tempArray for optout reason checks" . json_encode($tempArray) , ['batch_job_id' => $this->batch_job_id]);
            array_push($data, $tempArray);
        }

        //print_r($recordStudyIdTokenMapArray);
        if (count($data) > 0) {
            $this->module->log("Before Calling API:: Data Count::" . count($data));
            $this->startBatchAPIRequest($data, $recordStudyIdTokenMapArray);
        } else {
            //REDCap::logEvent(self::NCI_MODULE_LOG_NAME, "NO RECORD FOUND FOR PROCESSING (not eligible to process)");
            $this->module->log("Send Deidentified batch : NO RECORD FOUND FOR PROCESSING (not eligible to process)", ['batch_job_id' => $this->batch_job_id]);
        }

        return $this->batch_job_id . " - record count :: " . count($data);
    }

    /** This function helps to initiaize all the variable which are necessary to enable service functionality */
    private function iniGenerator()
    {
        //REDCap::logEvent(self::NCI_MODULE_LOG_NAME, "Module Init");
        //$this->module->log("Init Process Started", ['batch_job_id' => $this->batch_job_id]);
        $currnet_nci_env = $this->module->getProjectSetting("nciconnect-env"); // 1=DEV & 2=PROD


        if (isset($currnet_nci_env) && $currnet_nci_env == "1") {
            $this->nci_connect_api_key_file_loc = $this->module->getProjectSetting("dev-nciapikey-file-loc");
            $this->nci_connect_api_key = getAccessTokenFromKeyFile($this->nci_connect_api_key_file_loc);
            $this->nci_connect_api_endpoint = $this->module->getProjectSetting("dev-api-server-submit-participant-data-url");
        } else if (isset($currnet_nci_env) && $currnet_nci_env == "2") {
            $this->nci_connect_api_key_file_loc = $this->module->getProjectSetting("prod-nciapikey-file-loc");
            $this->nci_connect_api_key = getAccessTokenFromKeyFile($this->nci_connect_api_key_file_loc);
            $this->nci_connect_api_endpoint = $this->module->getProjectSetting("prod-api-server-submit-participant-data-url");
        }



        if (!empty($this->module->getProjectSetting("nci-token-store-field"))) {
            $this->ncitoken = $this->module->getProjectSetting("nci-token-store-field");
        }

        if (!empty($this->module->getProjectSetting("studyid-field-batch-process"))) {
            $this->inputstudyid = $this->module->getProjectSetting("studyid-field-batch-process");
        }

        if (!empty($this->nci_connect_api_endpoint)) {
            //TO-DO alternative design approch to find out URL
            $this->iv_status_api_endpoint = str_replace("submitParticipantsData", "identifyParticipant", $this->nci_connect_api_endpoint);
            $this->updateParticipantData_api_endpoint = str_replace("submitParticipantsData", "updateParticipantData", $this->nci_connect_api_endpoint);
            $this->consistencycheck_endpoint = str_replace("submitParticipantsData", "consistencyCheck", $this->nci_connect_api_endpoint);
        }

        if (!empty($this->module->getProjectSetting("deidentified-data-optout-field-list"))) {
            $this->optout_reason_field_list = $this->module->getProjectSetting("deidentified-data-optout-field-list");
        }

        if (isset($this->is_for_iv_table) && $this->is_for_iv_table == true) {
            if (!empty($this->module->getProjectSetting("iv-table-data-send-record-filter-logic"))) {
                $this->record_filter_logic = $this->module->getProjectSetting("iv-table-data-send-record-filter-logic");
            }

            if (!empty($this->module->getProjectSetting("iv-table-data-sent-status"))) {
                $this->deidentified_data_sent_status = $this->module->getProjectSetting("iv-table-data-sent-status");
            }

            if (!empty($this->module->getProjectSetting("iv-table-data-send-field-list"))) {
                $this->deidentified_data_send_field_list = $this->module->getProjectSetting("iv-table-data-send-field-list");
            }
        } else {
            if (!empty($this->module->getProjectSetting("deidentified-data-send-record-filter-logic"))) {
                $this->record_filter_logic = $this->module->getProjectSetting("deidentified-data-send-record-filter-logic");
            }

            if (!empty($this->module->getProjectSetting("deidentified-data-sent-status"))) {
                $this->deidentified_data_sent_status = $this->module->getProjectSetting("deidentified-data-sent-status");
            }

            if (!empty($this->module->getProjectSetting("deidentified-data-send-field-list"))) {
                $this->deidentified_data_send_field_list = $this->module->getProjectSetting("deidentified-data-send-field-list");
            }
        }


        if (!empty($this->module->getProjectSetting("batch_size_api_request"))) {
            $this->batch_size = $this->module->getProjectSetting("batch_size_api_request");
            // if batch size greater than 1000, set to default max size defined by NCI Connect API
            if (is_numeric($this->batch_size) > self::NCI_MAX_BATCH_SIZE) {

                $this->batch_size = self::NCI_MAX_BATCH_SIZE;
            }
            //Default to 500 for better API performance
            $this->batch_size = self::NCI_MAX_BATCH_SIZE;
        } else {
            $this->batch_size = self::NCI_MAX_BATCH_SIZE;
        }

        if (!empty($this->module->getProjectSetting("email_alert_from"))) {
            $this->email_alert_from = $this->module->getProjectSetting("email_alert_from");
        }



        if (!empty($this->module->getSubSettings("email-alert-notification-list"))) {
            $emaillistarray = array();
            $email_list = "";
            foreach ($this->module->getSubSettings("email-alert-notification-list") as $value) {
                array_push($emaillistarray, $value["email-for-alert-notification"]);
            }
            $email_list = implode(',', array_unique($emaillistarray));
            if (isset($email_list) && !empty($email_list)) {
                $this->email_alert_to = $email_list;
            }
        }

        $this->curr_proj_record_id_field = REDCap::getRecordIdField();
        //$this->module->log("Init Process Ended", ['batch_job_id' => $this->batch_job_id]);

    }

    function startBatchAPIRequest($redcap_data, $recordStudyIdTokenMapArray)
    {
        $requestbody = array();
        $responseDataArray = array();
        //print_r($redcap_data);
        //split data set into batch size 
        $chunk_data = array_chunk($redcap_data, $this->batch_size, true);

        //echo "### batch size " . $this->batch_size;
        //print_r($chunk_data);
        $this->module->log("After Chunk_Data");

        foreach ($chunk_data as $data) {
            //echo "### batch size count" . count($data);
            unset($requestbody);
            unset($responseDataArray);
            $requestbody = array();
            $responseDataArray = array();
            $requestbody["data"] = array_values($data);
            //print_r( $requestbody);
            $responseDataArray = $this->makeWebServiceRequest($requestbody);
            $this->module->log("WebServiceRequest Response Body" . print_r($responseDataArray, true));
            //echo "#### Resonse from web service request start</br>";
            //print_r($responseDataArray);
            //echo "#### Resonse from web service request end </br>";
            //check the response status and proceed
            if ($responseDataArray["code"] == 200) {
                //to update status back to REDCap record
                $this->writeData($data, $recordStudyIdTokenMapArray);
            }
        }
    }


    // This cURL function is used to make webservice API request to NCI Connect server
    private function makeWebServiceRequest($requestBody)
    {
        $curl = curl_init();
        $out_array = array();
        //remove preconsent or max time out population detail from request payload for submitParticipantData API use 03/30/2023
        $tempRequestBody = $requestBody;
        if (isset($tempRequestBody["data"][0][158291096])) { // opt out 
            unset($tempRequestBody["data"][0][158291096]);
            if (isset($tempRequestBody["data"][0][706283025]))
                unset($tempRequestBody["data"][0][706283025]);
        }
        if (isset($tempRequestBody["data"][0][875549268])) { //max time out
            unset($tempRequestBody["data"][0][875549268]);
        }
        if ($tempRequestBody["data"][0][821247024]) {  //Final identity verification match send status
            unset($tempRequestBody["data"][0][821247024]);
        }


        if (count($tempRequestBody["data"][0]) > 0) {
            $iv_table_requestBody = json_encode($tempRequestBody, JSON_NUMERIC_CHECK);

            $this->module->log("iv_table_reqBody endpoint $this->nci_connect_api_endpoint #### $this->nci_connect_api_key ");
            $this->module->log("API Request Payload   . " . $iv_table_requestBody, [
                'batch_job_id' => $this->batch_job_id
            ]);

            curl_setopt_array($curl, array(
                CURLOPT_URL => $this->nci_connect_api_endpoint,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => "",
                CURLOPT_FAILONERROR => true,
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => "POST",
                CURLOPT_POSTFIELDS => "$iv_table_requestBody",
                CURLOPT_HTTPHEADER => array(
                    "Content-Type: application/json",
                    "Authorization: Bearer $this->nci_connect_api_key",
                    "Content-Length: " . strlen($iv_table_requestBody)
                ),
            ));

            $response = curl_exec($curl);
            $err = curl_error($curl);

            if ($err) {
                REDCap::logEvent(self::NCI_MODULE_LOG_NAME, $err);
                $this->module->log("An error occurred on sending data to NCI after consistency check . " . $err, [
                    'batch_job_id' => $this->batch_job_id
                ]);
                REDCap::email($this->email_alert_to, $this->email_alert_from, 'NCICohortLink - Send De-identified Job Network Error ',  $err);
            }

            curl_close($curl);

            $this->module->log("API Call Response after submiting data to NCI ::" . $response, [
                'batch_job_id' => $this->batch_job_id
            ]);

            $out_array = json_decode($response, true);

            //Handle message errors from NCI
            if ($out_array["code"] != 200) {
                REDCap::logEvent(self::NCI_MODULE_LOG_NAME, $response);
                $this->module->log("An error occurred on sending data to NCI . " . $response, [
                    'batch_job_id' => $this->batch_job_id
                ]);

                // HP try to resubmit the same data like override scanario, it may fail but its ok to accept
                if (stripos($response,"not found") === false && stripos($response,"Invalid value") === false ) {
                    //$out_array["code"] == 200;
                    $this->module->log("An error occurred but no email trigged as it ok to accept as trying to resubmit." . $response, [
                        'batch_job_id' => $this->batch_job_id
                    ]);    
                } else {
                    REDCap::email($this->email_alert_to, $this->email_alert_from, 'NCICohortLink - Send De-identified Job Network Error ',  $response);    
                }   

                
            }
            if ($out_array["code"] == 200) {
                $this->deidentified_data_sent_success = true;
            }
        }


        

        foreach ($requestBody["data"] as $tmpRecord) {
            //print_r($requestBody["data"]);
            $url = "";
            $this->module->log("IV final verfication status logic starts" , ['batch_job_id' => $this->batch_job_id]);
            //$decision = $tmpRecord["final_iden_verifi_status"];
            $decision = $tmpRecord["821247024"];
            $decisionflag = "";
            if (isset($decision) && $decision == 197316935) {
                $decisionflag = "verified";
            } else if (isset($decision) && $decision == 219863910) {
                $decisionflag = "cannotbeverified";
            } else if (isset($decision) && $decision == 160161595) {
                $decisionflag = "outreachtimedout";
            } else if (isset($decision) && $decision == 922622075) {
                $decisionflag = "duplicate";
            }

	    $this->module->log("IV final verfication status logic starts :: $decision " , ['batch_job_id' => $this->batch_job_id]);            

            if (isset($decisionflag) && !empty($decisionflag)) {
                $url = $this->iv_status_api_endpoint . "?type=" . $decisionflag . "&token=" . $tmpRecord["token"];
                if (!empty($decisionflag) && isset($this->is_for_iv_table) && $this->is_for_iv_table == true) {
                    $this->module->log("IV status send URL  " . $url, ['batch_job_id' => $this->batch_job_id]);
                    $output = $this->sendIVfinalStatusFlag($url);
                    $outputtemp_array = json_decode($response, true);
                    if ($outputtemp_array["code"] == 200) {
                        $this->iv_status_api_sent_success = true;
                        $this->iv_status_api_sent_success_msg = $response;
                    } else {
                        //TODO
                    }
                }
            }

            if ((isset($tmpRecord["158291096"]) && $tmpRecord["158291096"] == "353358909") || (isset($tmpRecord["875549268"]) && $tmpRecord["875549268"] == "353358909")) {
                // send updateParticipantData request to set optout status 

                $nciUpdateArray = array();

                if (isset($tmpRecord["158291096"]) && $tmpRecord["158291096"] == "353358909") {
                    $nciUpdateArray["data"][0]["token"] = $tmpRecord["token"];
                    $nciUpdateArray["data"][0]["state"]["158291096"] = $tmpRecord["158291096"];
                    if (isset($tmpRecord["706283025"]) && count($tmpRecord["706283025"]) > 0) {
                        $nciUpdateArray["data"][0]["state"]["706283025"] = $tmpRecord["706283025"];
                    }
                }
                if (isset($tmpRecord["875549268"]) && $tmpRecord["875549268"] == "353358909") {
                    $nciUpdateArray["data"][0]["token"] = $tmpRecord["token"];
                    $nciUpdateArray["data"][0]["state"]["875549268"] = $tmpRecord["875549268"];
                }

                $curl = curl_init();
                $update_requestBody = json_encode($nciUpdateArray, JSON_NUMERIC_CHECK);
                curl_setopt_array($curl, array(
                    CURLOPT_URL => $this->updateParticipantData_api_endpoint,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => "",
                    CURLOPT_FAILONERROR => true,
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => "POST",
                    CURLOPT_POSTFIELDS => "$update_requestBody",
                    CURLOPT_HTTPHEADER => array(
                        "Content-Type: application/json",
                        "Authorization: Bearer $this->nci_connect_api_key",
                        "Content-Length: " . strlen($update_requestBody)
                    ),
                ));

                $response = curl_exec($curl);
                $updateErr = curl_error($curl);
                curl_close($curl);

                $this->module->log("updateParticipantData API Request Payload   . " . $update_requestBody, [
                    'batch_job_id' => $this->batch_job_id
                ]);

                if ($updateErr) {
                    REDCap::logEvent(self::NCI_MODULE_LOG_NAME, $updateErr);
                    $this->module->log("An error occurred on updateParticiapntData for optout or max reach out . $updateErr", ['batch_job_id' => $this->batch_job_id]);
                    echo "</br> ### " . $this->email_alert_to;
                    REDCap::email($this->email_alert_to, $this->email_alert_from, 'NCICohortLink - Send De-identified Job Network Error - updateParticipantData API',  $updateErr);
                    return false;
                }

                $update_out_array = json_decode($response, true);
                //Handle message errors from NCI
                if ($update_out_array["code"] != 200) {
                    REDCap::logEvent(self::NCI_MODULE_LOG_NAME, $response);
                    $this->module->log("An error occurred on sending data to NCI . " . $response, [
                        'batch_job_id' => $this->batch_job_id
                    ]);
                    REDCap::email($this->email_alert_to, $this->email_alert_from, 'NCICohortLink - Send De-identified Job Network Error - updateParticipantData API',  $response);
                    return false;
                }

                if ($update_out_array["code"] == 200) {
                    
                    return $update_out_array;
                }
            }
        }


        return $out_array;
    }


    private function consistencyCheck($body)
    {
        $curlCC = curl_init();

        //$this->module->log("the outbound request body for consistencyCheck###: $body");
        curl_setopt_array($curlCC, array(
            CURLOPT_URL => $this->consistencycheck_endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_FAILONERROR => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => "$body",
            CURLOPT_HTTPHEADER => array(
                "Content-Type: application/json",
                "Authorization: Bearer $this->nci_connect_api_key",
                "Content-Length: " . strlen($body)
            )
        ));

        //temp testing the outbound request body
        // $this->module->log("the outbound request body for consistencyCheck###: $body", ['batch_job_id' => $this->batch_job_id]);
        //echo "#### API Key </br> ".$this->nci_connect_api_key;
        //echo "#### API URL </br> ".$this->consistencycheck_endpoint;
        //print_r($body);
        $response = curl_exec($curlCC);
        $err = curl_error($curlCC);
        //var_dump($response);
        //var_dump($err);
        if ($err) {
            REDCap::logEvent(self::NCI_MODULE_LOG_NAME, $err);
            $this->module->log("An error occurred on consistency check . $err", [
                'batch_job_id' => $this->batch_job_id
            ]);
            REDCap::email($this->email_alert_to, $this->email_alert_from, 'NCICohortLink - Send De-identified Job Network Error',  $err);

            return false;
        }


        $out_array = json_decode($response, true);
        curl_close($curlCC);
        //Handle message errors from NCI
        if ($out_array["code"] != 200) {
            $this->module->log("consistencyCheck ERROR message ###: $response", ['batch_job_id' => $this->batch_job_id]);
            return false;
        } else if ($out_array["code"] == 200) {
            $this->module->log("consistencyCheck SUCCESS message ###: $response", ['batch_job_id' => $this->batch_job_id]);
            return true;
        }
    }
    private function sendIVfinalStatusFlag($url)
    {
        $error_msg = "";
        $nci_auth_headers = [
            "Authorization:Bearer $this->nci_connect_api_key"
        ];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $nci_auth_headers);
        $output = curl_exec($ch);

        if (curl_errno($ch)) {
            $error_msg = curl_error($ch);
            REDCap::logEvent(self::NCI_MODULE_LOG_NAME, $error_msg);
            $this->module->log("An error occurred . $error_msg", [
                'batch_job_id' => $this->batch_job_id
            ]);
            curl_close($ch);
            return $error_msg;
        }
        curl_close($ch);
        return $output;
    }

    private function writeData($data, $recordStudyIdTokenMapArray)
    {
        //minimal data write array
        
        
        $recordlist = array();
        foreach ($data as $record) {
            $writeTempArray = array();
            if (array_key_exists($record["token"], $recordStudyIdTokenMapArray)) {
                $writeTempArray[$this->curr_proj_record_id_field] = $recordStudyIdTokenMapArray[$record["token"]];
                if (isset($this->deidentified_data_sent_success) && $this->deidentified_data_sent_success == true ) {
                    $writeTempArray[$this->deidentified_data_sent_status] = 1;
                }

                if (isset($record["158291096"]) && $record["158291096"] == "353358909") {
                    $writeTempArray["sys_sent_precons_opt"] = 1;  // TODO with config variable
                }
                if (isset($this->is_preconsent_optout_stat_sent) && $this->is_preconsent_optout_stat_sent == 1) {
                }

                if (isset($record["875549268"]) && $record["875549268"] == "353358909") {
                    $writeTempArray["sys_sent_max_precon_reach"] = 1;  // TODO with config variable
                }

                if (isset($this->is_max_preconsent_con_reach) && $this->is_max_preconsent_con_reach == 1) {
                }


                if (isset($this->iv_status_api_sent_success) && $this->iv_status_api_sent_success == true) {
                    $writeTempArray["is_sent_iv_nci_done"] = 1;  // TODO with config variable
                    $writeTempArray["nci_iv_sent_response"] = $this->iv_status_api_sent_success_msg;
                }
            }
            array_push($recordlist, $writeTempArray);
        }

        $responseJSON = json_encode($recordlist);
        //print_r($responseJSON);
        $response = REDCap::saveData($this->module->getProjectId(), 'json', $responseJSON, 'overwrite', NULL, NULL, NULL, TRUE);
        //$this->module->log("Send Deidentified Data Status Saved To REDCap", ['batch_job_id' => $this->batch_job_id]);
    }
}
