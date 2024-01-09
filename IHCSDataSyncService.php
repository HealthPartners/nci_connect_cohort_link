<?php
namespace HealthPartners\Institute\NCIConnectCohortLink\Service;

use Exception;
use REDCap;

class IHCSDataSyncService
{
    //To Store Primay External Module Object
    private $module;
    private $nci_connect_api_key; // To hold NCI API key
    private $nci_connect_api_key_file_loc; // To hold the private key file to generate access token
    private $nci_connect_api_endpoint; // To hold NCI API endpoint
    private $record_filter_logic; // to hold record filter condition which helps to only include valid studyids for api request
    private $batch_size; // to hold number of records send part of each API request
    private $email_alert_from; // to hold from email address for email alert when error occurred
    private $email_alert_to; // to hold comma sepeated to email address for email alert when error occurred
    private $curr_proj_record_id_field;
    private $fields_send_with_token_request; // to hold list of field(s) send part of API request - array.
    private $ncitoken;
    private $datasync_field_list;
    private $is_DET; // Used to identify the job trigger by Data Entry Trigger

    private $is_withdraw_job; // Used to flag the job started for getting withdraw data items from NCI
    //To track Batch progress
    private $curr_batch;
    private $total_num_batch;
    private $total_num_record_processed;
    private $batch_job_id;

    const NCI_MAX_BATCH_SIZE = 500;
    const NCI_MODULE_LOG_NAME = "EM - NCI Data Sync Service";

    public function __construct($module)
    {
        $this->module = $module;
    }

    public function startNewBatchJob()
    {
        return $this->startBatchProcess();
    }

    public function startWithDrawDataSyncNewBatchJob()
    {   $this->is_withdraw_job = true;
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
        $data = array(); // used to send through API
        $this->module->log("Data Sync batch job started", ['batch_job_id' => $this->batch_job_id]);

        // Prepare Concept Id Map list    
        $redcap_conceptid_list = explode(',',  $this->datasync_field_list);
        foreach ($redcap_conceptid_list as $item) {
            $tempmap = explode("=", $item); // format : redcap_var_name=conceptid
            $redcap_conceptid_map[$tempmap[0]] = $tempmap[1];
        }

        if (!isset($this->fields_send_with_token_request)) {
            $this->fields_send_with_token_request = array();
        }
        if (isset($this->inputstudyid) && $this->inputstudyid == REDCap::getRecordIdField()) {
            array_push($this->fields_send_with_token_request, $this->inputstudyid);
        } else {
            array_push($this->fields_send_with_token_request, REDCap::getRecordIdField());
            array_push($this->fields_send_with_token_request, $this->inputstudyid);
        }

        //add nci_token to list of fields to extract from REDCap
        array_push($this->fields_send_with_token_request, $this->ncitoken);

        $redcap_data_with_record_id = REDCap::getData($this->module->getProjectId(), 'array', NULL, $this->fields_send_with_token_request, NULL, NULL, false, false, false, $this->record_filter_logic, false, false);


        //Prepare map with token and recordid 
        foreach ($redcap_data_with_record_id as $key => $event) {
            foreach ($event as $eventid => $dataitems) {
                // recordStudyIdTokenMapArray will used to write data back to REDCap record
                $recordStudyIdTokenMapArray[$dataitems[$this->ncitoken]]   = $dataitems[$this->curr_proj_record_id_field];
            }
        }

        if (count($redcap_data_with_record_id) > 0) {
            $this->startBatchAPIRequest($redcap_conceptid_map, $recordStudyIdTokenMapArray);
        } else {
            //REDCap::logEvent(self::NCI_MODULE_LOG_NAME, "NO RECORD FOUND FOR PROCESSING (not eligible to process)");
            $this->module->log("Data Sync batch job : NO RECORD FOUND FOR PROCESSING (not eligible to process)", ['batch_job_id' => $this->batch_job_id]);
        }

        //print_r($redcap_conceptid_map);
        //print_r($this->fields_send_with_token_request);
        //print_r($redcap_data_with_record_id);

        return $this->batch_job_id . " - record count :: " . count($redcap_data_with_record_id);
    }


    private function startBatchAPIRequest($redcap_conceptid_map, $recordStudyIdTokenMapArray)
    {
        $requestdata = array();
        $responseDataArray = array();
        $typelist = array("all"); // future performance tuning place
        foreach ($typelist as $type) {
            $limit = 500;
            $page = 1;
            $individualToken = "";
            //request individual if DET call 
            if ($this->isDETCall()) {
                foreach(array_keys($recordStudyIdTokenMapArray) as $key) {
                    $individualToken = $key;
                }
                $type="individual&token=".$individualToken;
            }

            //TODO If Withdrwal request apply filter.
            if (isset ($this->is_withdraw_job) && $this->is_withdraw_job == true)  {
                $type="all";
            }

            // Loop through until Limit and dataSize value differnce which means no other data to fetch through pageination
            do {
                unset($responseDataArray);
                $responseDataArray = array();
                $responseDataArray  = $this->makeWebServiceRequest($type, $limit, $page);
                //Write data into REDCap
                $this->writeData($responseDataArray, $redcap_conceptid_map, $recordStudyIdTokenMapArray);
                $page++;
            } while (count($responseDataArray) > 0 && $responseDataArray["code"] == 200 && array_key_exists("dataSize",$responseDataArray) && array_key_exists("limit",$responseDataArray)  && $responseDataArray["limit"] <= $responseDataArray["dataSize"]);
        }
    }

    /**
     * Write data into REDCap
     */
    private function writeData($data, $redcap_conceptid_map, $recordStudyIdTokenMapArray)
    {
        //minimal data write array
        $recordlist = array();
        foreach ($data["data"] as $index => $record) {
            $writeTempArray = array();
            $isUpdate = false; // used to track any data changes available to update in REDCap
            if (array_key_exists($record["token"], $recordStudyIdTokenMapArray)) {
                
                //extract values from response data using concept ids and map to redcap variable
                foreach ($redcap_conceptid_map as $redcap_var => $conceptid ) {
                     if (array_key_exists($conceptid, $record)) {
                        
                          
                          //Custom Logic to capture the Bio Specimen Check-in Dates
                          if($conceptid == "331584571" && isset($record[$conceptid]) && !empty($record[$conceptid])){
                              //Check if any check-in date data exists for Baseseline,Follow-up 1,Follow-up 2,Follow-up 3 and Follow-up 4
                                 //$this->module->log("BioSpecimen Entry Exists");
                              //Baseline Concept Id : 266600170
                              if(array_key_exists("266600170",$record[$conceptid])){
                                  if(isset($record[$conceptid][266600170]) && !empty($record[$conceptid][266600170])){
                                      //Get the Check-in Date value and assign this to the REDCap Array. Concept Id:840048338
                                      if(isset($record[$conceptid][266600170][840048338]) && !empty($record[$conceptid][266600170][840048338])) {
                                        $writeTempArray["nci_baseline_bioappt_dt"] = $record[$conceptid][266600170][840048338];
                                      }
                                  }
                              }
                              //Follow-up 1 Concept Id : 496823485
                              if(array_key_exists("496823485",$record[$conceptid])){
                                  if(isset($record[$conceptid][496823485]) && !empty($record[$conceptid][496823485])){
                                      //Get the Check-in Date value and assign this to the REDCap Array. Concept Id:840048338
                                      if(isset($record[$conceptid][496823485][840048338]) && !empty($record[$conceptid][496823485][840048338])) {
                                           $this->module->log("Writing Bio Specimen Baseline check-in date to REDCap");
                                          $writeTempArray["nci_follow_1_bioappt_dt"] = $record[$conceptid][496823485][840048338];
                                      }
                                  }
                              }
                              //Follow-up 2 Concept Id : 650465111
                              if(array_key_exists("650465111",$record[$conceptid])){
                                  if(isset($record[$conceptid][650465111]) && !empty($record[$conceptid][650465111])){
                                      //Get the Check-in Date value and assign this to the REDCap Array. Concept Id:840048338
                                      if(isset($record[$conceptid][650465111][840048338]) && !empty($record[$conceptid][650465111][840048338])) {
                                          $writeTempArray["nci_follow_2_bioappt_dt"] = $record[$conceptid][650465111][840048338];
                                      }
                                  }
                              }
                              //Follow-up 3 Concept Id : 303552867
                              if(array_key_exists("303552867",$record[$conceptid])){
                                  if(isset($record[$conceptid][303552867]) && !empty($record[$conceptid][303552867])){
                                      //Get the Check-in Date value and assign this to the REDCap Array. Concept Id:840048338
                                      if(isset($record[$conceptid][303552867][840048338]) && !empty($record[$conceptid][303552867][840048338])) {
                                          $writeTempArray["nci_follow_3_bioappt_dt"] = $record[$conceptid][303552867][840048338];
                                      }
                                  }
                              }

                          } elseif ($conceptid == "685002411" && isset($record[$conceptid]) && !empty($record[$conceptid])) {
                              // To support Refusal and withdral data pull  
                              if(isset($record[$conceptid][994064239]) && !empty($record[$conceptid][994064239])) {
                                $writeTempArray["nci_bl_act_ini_survey"] = $record[$conceptid][994064239];
                              }   
                              if(isset($record[$conceptid][194410742]) && !empty($record[$conceptid][194410742])) {
                                $writeTempArray["nci_bl_act_blood_don"] = $record[$conceptid][194410742];
                              }   
                              if(isset($record[$conceptid][949501163]) && !empty($record[$conceptid][949501163])) {
                                $writeTempArray["nci_bl_act_urine_don"] = $record[$conceptid][949501163];
                              }     
                              if(isset($record[$conceptid][277479354]) && !empty($record[$conceptid][277479354])) {
                                $writeTempArray["nci_bl_act_saliva_don"] = $record[$conceptid][277479354];
                              }
                              if(isset($record[$conceptid][217367618]) && !empty($record[$conceptid][217367618])) {
                                $writeTempArray["nci_bl_act_speci_sur"] = $record[$conceptid][217367618];
                              }
                              if(isset($record[$conceptid][867203506]) && !empty($record[$conceptid][867203506])) {
                                $writeTempArray["nci_follow_act_sur"] = $record[$conceptid][867203506];
                              }
                              if(isset($record[$conceptid][352996056]) && !empty($record[$conceptid][352996056])) {
                                $writeTempArray["nci_follow_act_speci"] = $record[$conceptid][352996056];
                              }

                          } else {
                             $writeTempArray[$redcap_var] = $record[$conceptid];
                         }

                         $isUpdate = true;
                            
                        //Custom Logic to change REDCap instrument status
                        if ($conceptid == "Connect_ID" &&  isset($record[$conceptid]) && !empty($record[$conceptid])) {
                            $writeTempArray["outreach_tracking_complete"] = "2";
                            $writeTempArray["outreach_script_complete"] = "2";
                            $writeTempArray["registration_tracking_complete"] = "2";
                            $writeTempArray["registration_script_complete"] = "2";
                            $writeTempArray["consent_tracking_complete"] = "2";
                            $writeTempArray["consent_script_complete"] = "2";
                        }
                        // NCI Date of Signup Completion
                        if ($conceptid == "335767902" &&  isset($record[$conceptid]) && !empty($record[$conceptid])) {
                            $writeTempArray["registration_tracking_complete"] = "2";
                            $writeTempArray["registration_script_complete"] = "2";
                        }

                        // NCI - Site Date of Identity Verification Completion
                        if ($conceptid == "914594314" &&  isset($record[$conceptid]) && !empty($record[$conceptid])) {
                            $writeTempArray["id_verification_tracking_complete"] = "2";    
                        }

                        //NCI - participant withdraw at Connect site
                        if ( $conceptid == "906417725" &&  isset($record[$conceptid]) && !empty($record[$conceptid]) && $record[$conceptid]=="353358909") {
                            $writeTempArray["nci_connect_record_detail_complete"] = "1";                                
                            $writeTempArray["basic_demography_complete"] = "1";
                        }

                     }
                }
                if ($isUpdate) {
                    $writeTempArray[$this->curr_proj_record_id_field] = $recordStudyIdTokenMapArray[$record["token"]];
                }
                
            }
            if (count ($writeTempArray) > 0 ) {
                array_push($recordlist, $writeTempArray);
            }
        }

        $responseJSON = json_encode($recordlist);
       //var_dump ($responseJSON);
        //$this->module->log("Data Sync Status Saved To REDCap response JSON #### $responseJSON", ['batch_job_id' => $this->batch_job_id]);
        $response = REDCap::saveData($this->module->getProjectId(), 'json', $responseJSON, 'overwrite', NULL, NULL, NULL, TRUE);

        foreach($response as $key => $value){
            if($key == "errors" && is_array($value) && count ($value) > 0 ){
                $this->module->log("Data Sync Status Saved To REDCap Error message #### ". json_encode($value) , ['batch_job_id' => $this->batch_job_id]);
            }
            if ($key == "item_count") {
                $this->module->log("Data Sync Status Saved To REDCap Item count ####". $value , ['batch_job_id' => $this->batch_job_id]);
            }
        }
        //var_dump($response);
        //$this->module->log("Data Sync Status Saved To REDCap ####". json_encode($response) , ['batch_job_id' => $this->batch_job_id]);
    }

    /**
     * Get Participant Data with limit and page conditions
     */
    private function makeWebServiceRequest($type, $limit, $page)
    {

        $curl = curl_init();
        $endpoint = $this->nci_connect_api_endpoint . "?type=" . $type . "&limit=" . $limit . "&page=" . $page;
        curl_setopt_array($curl, array(
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_FAILONERROR => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => array(
                "Content-Type: application/json",
                "Authorization: Bearer $this->nci_connect_api_key"
            ),
        ));

        //temp testing the outbound request body
        $this->module->log("the outbound request endpoint: $endpoint", ['batch_job_id' => $this->batch_job_id]);

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
        //echo "endpoint : $endpoint <br/>";
        //var_dump($response);
         //temp testing the outbound request body
        //$this->module->log("the received data:  $response ", ['batch_job_id' => $this->batch_job_id]);

        $out_array = json_decode($response, true);
        return $out_array;
    }

    private function iniGenerator()
    {
        $currnet_nci_env = $this->module->getProjectSetting("nciconnect-env"); // 1=DEV & 2=PROD
        if (isset($currnet_nci_env) && $currnet_nci_env == "1") {
            $this->nci_connect_api_key_file_loc = $this->module->getProjectSetting("dev-nciapikey-file-loc");
            $this->nci_connect_api_key = getAccessTokenFromKeyFile($this->nci_connect_api_key_file_loc);
            $this->nci_connect_api_endpoint = $this->module->getProjectSetting("dev-api-server-get-participant-data-url");
        } else if (isset($currnet_nci_env) && $currnet_nci_env == "2") {
            $this->nci_connect_api_key_file_loc = $this->module->getProjectSetting("prod-nciapikey-file-loc");
            $this->nci_connect_api_key = getAccessTokenFromKeyFile($this->nci_connect_api_key_file_loc);
            $this->nci_connect_api_endpoint = $this->module->getProjectSetting("prod-api-server-get-participant-data-url");
        }

        if (!empty($this->module->getProjectSetting("nci-token-store-field"))) {
            $this->ncitoken = $this->module->getProjectSetting("nci-token-store-field");
        }

        if (!empty($this->module->getProjectSetting("studyid-field-batch-process"))) {
            $this->inputstudyid = $this->module->getProjectSetting("studyid-field-batch-process");
        }

        // To check if the request for withdraw data sync job
        if (isset ($this->is_withdraw_job) && $this->is_withdraw_job == true) { 
            if (!empty($this->module->getProjectSetting("withdrawdatasync-record-filter-logic"))) {
                $this->record_filter_logic = $this->module->getProjectSetting("withdrawdatasync-record-filter-logic");
            }

            if (!empty($this->module->getProjectSetting("withdrawdatasync-field-list"))) {
                $this->datasync_field_list = $this->module->getProjectSetting("withdrawdatasync-field-list");
            }

        } else {

            if (!empty($this->module->getProjectSetting("datasync-record-filter-logic"))) {
                $this->record_filter_logic = $this->module->getProjectSetting("datasync-record-filter-logic");
            }

            if (!empty($this->module->getProjectSetting("datasync-field-list"))) {
                $this->datasync_field_list = $this->module->getProjectSetting("datasync-field-list");
            }
        }

        if (!empty($this->module->getProjectSetting("datasync-record-filter-logic"))) {
            //$this->record_filter_logic = $this->module->getProjectSetting("datasync-record-filter-logic");
            if (isset($this->record_filter_logic_record_level) && !empty($this->record_filter_logic_record_level)) {
                $this->record_filter_logic = $this->record_filter_logic  . $this->record_filter_logic_record_level;
            }
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

        $this->curr_proj_record_id_field = REDCap::getRecordIdField();
    }



     // This method will be called by the redcap_data_entry_form hook
     public function redcap_data_entry_form_top($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance)
     {
         $this->adhoctriggerform_list_array = array();
         foreach ($this->module->getSubSettings("adhoctriggerform-list") as $key => $value ){
             foreach($value["adhoctrigger-form"] as $formkey => $formvalue) {
                 array_push($this->adhoctriggerform_list_array, $formvalue);
             }
 
         }
         $this->adhoctriggerform_list_array =  array_unique($this->adhoctriggerform_list_array) ;
         if ( isset($this->adhoctriggerform_list_array) &&  count($this->adhoctriggerform_list_array) > 0 && in_array($instrument, $this->adhoctriggerform_list_array)) {
             $record_id_field = REDCap::getRecordIdField();
             $this->record_filter_logic_record_level  =  "  AND ( [$record_id_field] = \"$record\" )";
             //REDCap::logEvent(self::NCI_MODULE_LOG_NAME, "DATA ENTRY TRIGGER INITIATED", NULL,NULL,NULL, $project_id);
             $this->module->log("DATA SYNC DATA ENTRY TRIGGER INITIATED $project_id :" . $project_id . " record Id:" . $record);
             $this->is_DET = true;
             $this->startNewBatchJob();
         }
     }
 
     //To check if its DET call or not
    public function isDETCall(){
        if (isset($this->is_DET) && $this->is_DET == true) {
            return true;
        } else {
            return false;
        }
    }
}
