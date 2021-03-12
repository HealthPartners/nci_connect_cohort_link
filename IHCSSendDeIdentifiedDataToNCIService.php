<?php
namespace HealthPartners\Institute\NCIConnectCohortLink\Service;

use Exception;
use REDCap;

class IHCSSendDeIdentifiedDataToNCIService
{
    //To Store Primay External Module Object
    private $module;

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
    //To track Batch progress
    private $curr_batch;
    private $total_num_batch;
    private $total_num_record_processed;
    private $batch_job_id;

    const NCI_MAX_BATCH_SIZE = 500;

    public function __construct($module)
    {
        $this->module = $module;
    }

    public function startNewBatchJob()
    {
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
        $data = array() ; // used to send through API
        $this->module->log("Send Deidentified batch job started", ['batch_job_id' => $this->batch_job_id]);

        //get all the list of data items from REDCap
        if (!isset ($this->fields_send_with_token_request)) {
            $this->fields_send_with_token_request = array();
            $redcap_conceptid_list = explode(',',  $this->deidentified_data_send_field_list); 
            foreach ($redcap_conceptid_list as $item) {
                $tempmap = explode("=",$item); // format : redcap_var_name=conceptid
                $redcap_conceptid_map [$tempmap[0]] = $tempmap[1];
                array_push($this->fields_send_with_token_request, $tempmap[0]);
            }
        }
        
        if (isset($this->inputstudyid) && $this->inputstudyid == REDCap::getRecordIdField() ) {
            array_push($this->fields_send_with_token_request, $this->inputstudyid);
        } else {
            array_push($this->fields_send_with_token_request, REDCap::getRecordIdField());
            array_push($this->fields_send_with_token_request, $this->inputstudyid);
        }

        //add nci_token to list of fields to extract from REDCap
        array_push($this->fields_send_with_token_request, $this->ncitoken);
        

        $redcap_data_with_record_id = REDCap::getData($this->module->getProjectId() , 'array', NULL, $this->fields_send_with_token_request, NULL, NULL, false, false, false, $this->record_filter_logic, false, false);

        //Prepare the data with concept id
        foreach ($redcap_data_with_record_id as $key => $event) {
            $tempArray= array();
            foreach ($event as $eventid => $dataitems) {
                foreach ($dataitems as $field => $val){
                    if (array_key_exists($field,$redcap_conceptid_map)) {// to find out match concept id variable
                        $tempArray [$redcap_conceptid_map[$field]] = $val;
                    } else {
                        //$data [$field] = $val;
                    }
                }
                // recordStudyIdTokenMapArray will used to write data back to REDCap record
                $recordStudyIdTokenMapArray [$dataitems[$this->ncitoken]]   = $dataitems[$this->curr_proj_record_id_field];
            }
            array_push($data,$tempArray);
        }

        //print_r($recordStudyIdTokenMapArray);
        if (count($data) > 0){
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
            $this->nci_connect_api_key = $this->module->getProjectSetting("dev-nciapikey");
            $this->nci_connect_api_endpoint = $this->module->getProjectSetting("dev-api-server-submit-participant-data-url");
        } else if (isset($currnet_nci_env) && $currnet_nci_env == "2") {
            $this->nci_connect_api_key = $this->module->getProjectSetting("prod-nciapikey");
            $this->nci_connect_api_endpoint = $this->module->getProjectSetting("prod-api-server-submit-participant-data-url");
        }

        if (!empty($this->module->getProjectSetting("nci-token-store-field"))) {
            $this->ncitoken = $this->module->getProjectSetting("nci-token-store-field");
        }

        if (!empty($this->module->getProjectSetting("studyid-field-batch-process"))) {
            $this->inputstudyid = $this->module->getProjectSetting("studyid-field-batch-process");
        }

        if (!empty($this->module->getProjectSetting("deidentified-data-send-record-filter-logic"))) {
            $this->record_filter_logic = $this->module->getProjectSetting("deidentified-data-send-record-filter-logic");
        }

        if (!empty($this->module->getProjectSetting("batch_size_api_request"))) {
            $this->batch_size = $this->module->getProjectSetting("batch_size_api_request");
            // if batch size greater than 1000, set to default max size defined by NCI Connect API
            if (is_numeric($this->batch_size) > self::NCI_MAX_BATCH_SIZE) {
                $this->batch_size = self::NCI_MAX_BATCH_SIZE;
            }
        } else {
            $this->batch_size = self::NCI_MAX_BATCH_SIZE;
        }

        if (!empty($this->module->getProjectSetting("email_alert_from"))) {
            $this->email_alert_from = $this->module->getProjectSetting("email_alert_from");
        }

        if (!empty($this->module->getProjectSetting("deidentified-data-sent-status"))) {
            $this->deidentified_data_sent_status = $this->module->getProjectSetting("deidentified-data-sent-status");
        }

        if (!empty($this->module->getSubSettings("email-alert-notification-list"))) {
            $emaillistarray = array();
            $email_list = "";
            foreach ($this->module->getSubSettings("email-alert-notification-list") as $value) {
                array_push($emaillistarray, $value["email-for-alert-notification"]);
            }
            $email_list = implode(', ', array_unique($emaillistarray));
            if (isset($email_list) && !empty($email_list)) {
                $this->email_alert_to = $email_list;
            }
        }

        if (!empty($this->module->getProjectSetting("deidentified-data-send-field-list"))) {
            $this->deidentified_data_send_field_list = $this->module->getProjectSetting("deidentified-data-send-field-list");
        }

        
        $this->curr_proj_record_id_field = REDCap::getRecordIdField();
        //$this->module->log("Init Process Ended", ['batch_job_id' => $this->batch_job_id]);
    }

    function startBatchAPIRequest($redcap_data, $recordStudyIdTokenMapArray){
        $requestdata = array();
        $responseDataArray = array();

        //split data set into batch size 
        $chunk_data = array_chunk($redcap_data, $this->batch_size, true);

        foreach ($chunk_data as $data) {

            $requestbody = array();
            $requestbody["data"] = $data;
            //print_r( $requestbody);
            $responseDataArray = $this->makeWebServiceRequest(json_encode($requestbody));
            //print_r($responseDataArray);
            //check the response status and proceed
            if ($responseDataArray ["code"] == 200 ) {
                //to update status back to REDCap record
                $this->writeData($data,$recordStudyIdTokenMapArray);
                
            }
        }

    }


        // This cURL function is used to make webservice API request to NCI Connect server
    private function makeWebServiceRequest($requestBody)
        {
            $curl = curl_init();
    
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
                CURLOPT_POSTFIELDS => "$requestBody",
                CURLOPT_HTTPHEADER => array(
                    "Content-Type: application/json",
                    "Authorization: Bearer $this->nci_connect_api_key",
                    "Content-Length: " . strlen($requestBody)
                ) ,
            ));
    
            $response = curl_exec($curl);
            $err = curl_error($curl);
    
            if ($err) {
                REDCap::logEvent(self::NCI_MODULE_LOG_NAME, $err);
                $this->module->log("An error occurred . $err", [
                    'batch_job_id' => $this->batch_job_id
                ]);
                REDCap::email(implode(', ', $this->email_alert_to), $this->email_alert_from, 'NCICohortLink - Send De-identified Job Network Error',  $err);
            }
    
            curl_close($curl);
            $out_array = json_decode($response, true);
    
            //Handle message errors from NCI
            if ($out_array["code"] != 200)
            {
                $data_formatted = array();
                foreach ($out_array as $this_field => $this_value)
                {
                    $data_formatted[] = "$this_field = '$this_value'";
                }
                $data_changes = implode(",\n", $data_formatted);
                REDCap::logEvent(self::NCI_MODULE_LOG_NAME, $data_changes);
            }
            return $out_array;
        }

        private function writeData($data,$recordStudyIdTokenMapArray){
            //minimal data write array
            $recordlist = array();
            foreach ($data as $record) {
                $writeTempArray = array();
                if (array_key_exists( $record["token"] , $recordStudyIdTokenMapArray)){
                   $writeTempArray[$this->curr_proj_record_id_field] = $recordStudyIdTokenMapArray[$record["token"]];    
                   $writeTempArray[$this->deidentified_data_sent_status] = 1;
                }
                array_push($recordlist,$writeTempArray);
            }
            
            $responseJSON = json_encode($recordlist);
            print_r($responseJSON);
            $response = REDCap::saveData($this->module->getProjectId() , 'json', $responseJSON, 'overwrite',NULL,NULL,NULL,TRUE);
            $this->module->log("Send Deidentified Data Status Saved To REDCap", ['batch_job_id' => $this->batch_job_id]);
        }

}
