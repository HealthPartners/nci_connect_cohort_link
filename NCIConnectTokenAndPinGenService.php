<?php
namespace HealthPartners\Institute\NCIConnectCohortLink\Service;

use Exception;
use REDCap;

class NCIConnectTokenAndPinGenService
{
    //To Store Primay External Module Object
    private $module;

    private $nciTokenAndPINGenService;
    private $nci_connect_api_key; // To hold NCI API key
    private $nci_connect_api_key_file_loc; // To hold the private key file to generate access token
    private $nci_connect_api_endpoint; // To hold NCI API endpoint
    private $inputstudyid; // To hold input study id field
    private $outputtoken; //To hold output token field name
    private $outputtokenurl;
    private $outputpin; //To hold output pin field name
    private $record_filter_logic; // to hold record filter condition which helps to only include valid studyids for api request
    private $fields_send_with_token_request; // to hold list of field(s) send part of token API request.
    private $batch_size; // to hold number of records send part of each API request
    private $email_alert_from;// to hold from email address for email alert when error occurred
    private $email_alert_to;// to hold comma sepeated to email address for email alert when error occurred
    private $record_filter_logic_record_level; // Used for DET
    private $adhoctriggerform_filter_logic ; // to used to hold record filter logic when instrument open
    private $adhoctriggerform_list_array; // used to store list of instruments register for adhoc trigger
    private $nci_connect_pwa_endpoint; //Used to store PWA app URL 
    //To track Batch progress
    private $curr_batch;
    private $total_num_batch;
    private $total_num_record_processed;
    private $batch_job_id;
    private $is_DET; // Used to identify the job trigger by Data Entry Trigger
    private $curr_proj_record_id_field;

    const IS_IMPORT_CURRENTLY_INPROGRESS = "is_import_currently_inprogress";
    const IS_FORCE_BATCH_STOP_SET = "is_force_batch_stop_set";
    const YES = "Y";
    const NO = "N";
    const NCI_MAX_BATCH_SIZE = 999;
    const CURR_BATCH = "CURR_BATCH";
    const TOTAL_NUM_BATCH = "TOTAL_NUM_BATCH";
    const TOTAL_NUM_RECORD_PROCESSED = "TOTAL_NUM_RECORD_PROCESSED";
    const TOTAL_RECORDS_IN_BATCH = "TOTAL_RECORDS_IN_BATCH";
    const BATCH_JOB_ID = "BATCH_JOB_ID";
    const NCI_MODULE_LOG_NAME = "EM - NCI Token and PIN Generator ";

    public function __construct($module){

        $this->module = $module;

    }
    public function startNewBatchJob(){
        return $this->startBatchProcess();
    }
 
    // This function helps to force stop the current running job after the current running batch
    public function forceBatchStop(){
        $this->module->setProjectSetting(self::IS_FORCE_BATCH_STOP_SET, self::YES);
        return true;
    }

    // This function used to start the batch process and manage pre & post batch job tasks
    private function startBatchProcess(){
        $this->batch_job_id = uniqid("batch", true);
        $this->iniGenerator();
        //to run new batch job if not already in progress
        if (!$this->isBatchJobLocked())
        {
            if(!$this->isDETCall()){
                $this->clearCurrentBatchJobStatus();
            }

            //REDCap::logEvent(self::NCI_MODULE_LOG_NAME, "Batch job started");
            $this->module->log("Batch job started", ['batch_job_id' => $this->batch_job_id]);
            //Add studyid field into part of list of fields for extract from REDCap project
            if (!isset ($this->fields_send_with_token_request)) {
                $this->fields_send_with_token_request = array();
            }
            
            if (isset($this->inputstudyid) && $this->inputstudyid == REDCap::getRecordIdField() ) {
                array_push($this->fields_send_with_token_request, $this->inputstudyid);
            } else {
                array_push($this->fields_send_with_token_request, REDCap::getRecordIdField());
                array_push($this->fields_send_with_token_request, $this->inputstudyid);
            }

            if ( $this->record_filter_logic != "") {
                $data = REDCap::getData($this->module->getProjectId() , 'array', NULL, $this->fields_send_with_token_request, NULL, NULL, false, false, false, $this->record_filter_logic, false, false);
            }
            
            if(!$this->isDETCall()) {
                //To create batch job detail and lock the job at project level
                $this->module->setProjectSetting(self::IS_IMPORT_CURRENTLY_INPROGRESS, self::YES);
            }
            //Start the Batch API request task if anything to process
            if (count($data) > 0){
                $this->startBatchAPIRequest($data);
            } else {
                //REDCap::logEvent(self::NCI_MODULE_LOG_NAME, "NO RECORD FOUND FOR PROCESSING (not eligible to process)");
                $this->module->log("NO RECORD FOUND FOR PROCESSING (not eligible to process)", ['batch_job_id' => $this->batch_job_id]);
            }
            if(!$this->isDETCall()){
                $this->module->setProjectSetting(self::IS_IMPORT_CURRENTLY_INPROGRESS, self::NO);
                $this->clearCurrentBatchJobStatus();
            }
            return true;
        } else {
            //currenlty no action needed as it just reply
            return false;
        }
        //Return default status - there is currenlty another batch in progress
        return false;
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
             $this->nci_connect_api_endpoint = $this->module->getProjectSetting("dev-api-server-get-participant-token-url");
             $this->nci_connect_pwa_endpoint = $this->module->getProjectSetting("dev-api-server-pwa-url");
         } else if (isset($currnet_nci_env) && $currnet_nci_env == "2") {
             $this->nci_connect_api_key_file_loc = $this->module->getProjectSetting("prod-nciapikey-file-loc");
             $this->nci_connect_api_key = getAccessTokenFromKeyFile($this->nci_connect_api_key_file_loc);
             $this->nci_connect_api_endpoint = $this->module->getProjectSetting("prod-api-server-get-participant-token-url");
             $this->nci_connect_pwa_endpoint = $this->module->getProjectSetting("prod-api-server-pwa-url");
         }

         if (!empty($this->module->getProjectSetting("studyid-field-batch-process"))) {
             $this->inputstudyid = $this->module->getProjectSetting("studyid-field-batch-process");
         }
         if (!empty($this->module->getProjectSetting("nci-token-store-field"))) {
             $this->outputtoken = $this->module->getProjectSetting("nci-token-store-field");
         }
         if (!empty($this->module->getProjectSetting("nci-token-url-store-field"))) {
            $this->outputtokenurl = $this->module->getProjectSetting("nci-token-url-store-field");
        }

         if (!empty($this->module->getProjectSetting("nci-pin-store-field"))) {
             $this->outputpin = $this->module->getProjectSetting("nci-pin-store-field");
         }

         if (!empty($this->module->getProjectSetting("adhoctriggerform-filter-logic"))) {
             $this->adhoctriggerform_filter_logic  = $this->module->getProjectSetting("adhoctriggerform-filter-logic");
         }

         if (!empty($this->module->getProjectSetting("record-filter-logic"))) {
             $this->record_filter_logic = $this->module->getProjectSetting("record-filter-logic");
             if (isset($this->record_filter_logic_record_level) && !empty($this->record_filter_logic_record_level)) {
                 $this->record_filter_logic = $this->adhoctriggerform_filter_logic  . $this->record_filter_logic_record_level;
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


         if (!empty($this->module->getSubSettings("email-alert-notification-list")))
         {
             $emaillistarray = array();
             $email_list = "";
             foreach ($this->module->getSubSettings("email-alert-notification-list") as $value)
             {
                 array_push($emaillistarray, $value["email-for-alert-notification"]);
             }
             $email_list = implode(', ', array_unique($emaillistarray));
             if (isset($email_list) && !empty($email_list)){
                 $this->email_alert_to = $email_list;
             }
         }

         $this->curr_proj_record_id_field = REDCap::getRecordIdField();
         //$this->module->log("Init Process Ended", ['batch_job_id' => $this->batch_job_id]);
     }

    //This checks if already any batch job currently in progress for this job
    public function isBatchJobLocked()
    {
        if($this->isDETCall()){
            return false; // continue new batch job for DET
        }

        //This is used to check if already import process currenlty running
        $is_import_inprogress = $this->module->getProjectSetting(self::IS_IMPORT_CURRENTLY_INPROGRESS);
        if (is_string($is_import_inprogress) && $is_import_inprogress == self::YES) {
            return true;
        }
        else if (is_string($is_import_inprogress) && $is_import_inprogress == self::NO) {
            return false;
        } else {
            // Set default false to support first timejob run;
            return false;
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



//Force Clear Batch Job Lock
    public function forceClearBatchJobLock()
    {
        $this->module->setProjectSetting(self::IS_IMPORT_CURRENTLY_INPROGRESS, self::NO);
    }

    public function getBatchDataItem($item)
    {
        return $this->module->getProjectSetting($item);
    }

    // This is the function does the API request to NCI Connect Server
    private function startBatchAPIRequest($redcap_data)
    {

        //initialize batch job tracking variable
        $this->curr_batch = 0;
        $this->total_num_batch = 0;
        $this->total_num_record_processed = 0;
        $requestdata = array();
        $responseDataArray = array();
        $recordStudyIdMapArray = array();
        $record_count = 0;
        $batch_count = 0;
        $total_record = count($redcap_data);


        $chunk_data = array_chunk($redcap_data, $this->batch_size, true);
        if(!$this->isDETCall()){
            //Batch job tracking
            $this->module->setProjectSetting(self::BATCH_JOB_ID, $this->batch_job_id);
            $this->module->setProjectSetting(self::TOTAL_NUM_BATCH, count($chunk_data));
            $this->module->log("Batch Job Total Record :  $total_record", ['batch_job_id' => $this->batch_job_id]);
            $this->module->log("Batch Job Total Batch :  " . count($chunk_data), ['batch_job_id' => $this->batch_job_id]);
        }
        foreach ($chunk_data as $data) {
            unset($requestdata); 
            $requestdata = array(); 
            if(!$this->isDETCall()){
                $this->module->setProjectSetting(self::CURR_BATCH, $this->curr_batch);
                $this->module->setProjectSetting(self::TOTAL_NUM_RECORD_PROCESSED, $record_count);
            }
            foreach ($data as $recordid => $event)
            {
                foreach ($event as $eventid => $curr_record)
                {
                    // This will used to save data back to REDCap

                    $recordStudyIdMapArray[$curr_record[$this->inputstudyid]] = $curr_record[$this->curr_proj_record_id_field];

                    $paramarray = array();
                    $paramarray["studyId"] = $curr_record[$this->inputstudyid];
                    /*foreach ($this->fields_send_with_token_request as $field)
                    {
                        if (strtolower($field) != strtolower("studyId"))
                        { // exclude studyId as it already added
                            $paramarray[$field] = $curr_record[$field];
                        }
                    }*/


                    if (isset($curr_record[$this->inputstudyid]) && !empty($curr_record[$this->inputstudyid])) {
                        array_push($requestdata, $paramarray);
                    }
                    $record_count++;
                }
            }
            $requestbody = array();
            $requestbody["data"] = $requestdata;
            $responseDataArray = $this->makeWebServiceRequest(json_encode($requestbody));

            //Write the response to REDCap Record
            $this->writeData($responseDataArray, $recordStudyIdMapArray);
            $this->curr_batch = $this->curr_batch + 1;
            if(!$this->isDETCall()){
                $force_stop = $this->module->getProjectSetting(self::IS_FORCE_BATCH_STOP_SET);
            }
            if (isset($force_stop) && $force_stop == self::YES) {
                //REDCap::logEvent(self::NCI_MODULE_LOG_NAME, "Force Stop Initiated");
                $this->module->log("Force Stop Initiated", ['batch_job_id' => $this->batch_job_id]);
                //$this->clearCurrentBatchJobStatus();
                break; // exit from continue processing becaus of force stop initiated
            }

        }

    }

    // Clears the current batch job status
    public function clearCurrentBatchJobStatus()
    {
        $this->module->removeProjectSetting(self::BATCH_JOB_ID);
        $this->module->removeProjectSetting(self::IS_IMPORT_CURRENTLY_INPROGRESS);
        $this->module->removeProjectSetting(self::TOTAL_NUM_BATCH);
        $this->module->removeProjectSetting(self::TOTAL_NUM_RECORD_PROCESSED);
        $this->module->removeProjectSetting(self::CURR_BATCH);
        $this->module->removeProjectSetting(self::IS_FORCE_BATCH_STOP_SET);
        //REDCap::logEvent(self::NCI_MODULE_LOG_NAME, "Cleared Batch job");
        $this->module->log("Cleared Batch Job Settings", ['batch_job_id' => $this->batch_job_id]);
    }

    private function writeData($responseDataArray,$recordStudyIdMapArray)
    {
        //REDCap support only all lower case
        $responseDataArrayAllLowerCaseKey = array();
        foreach ($responseDataArray["data"] as $eachItem)
        {
            $newArray = array_change_key_case($eachItem, CASE_LOWER);
            $newArray[$this->outputtoken] = $newArray["token"]; // API Response from NCI
            $newArray[$this->outputpin] = $newArray["pin"]; // API Response from NCI
            $newArray[$this->outputtokenurl] = $this->nci_connect_pwa_endpoint.$newArray["token"];
            if ($this->inputstudyid != "studyid") {
                $newArray[$this->inputstudyid] = $newArray["studyid"];
                unset($newArray["studyid"]);
            }
            if ($this->inputstudyid != $this->curr_proj_record_id_field ){ // add recordid field
                $newArray[$this->curr_proj_record_id_field] = $recordStudyIdMapArray[$newArray[$this->inputstudyid]];
            }
            unset($newArray["token"]);
            unset($newArray["pin"]);

            array_push($responseDataArrayAllLowerCaseKey, $newArray);
        }
        $responseJSON = json_encode($responseDataArrayAllLowerCaseKey);
        $response = REDCap::saveData($this->module->getProjectId() , 'json', $responseJSON, 'overwrite');
        //REDCap::logEvent(self::NCI_MODULE_LOG_NAME, "Batch Token/PIN Data Saved To REDCap");
        $this->module->log("Batch Token/PIN Data Saved To REDCap", ['batch_job_id' => $this->batch_job_id]);
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

        //temp testing the outbound request body
        $this->module->log("the outbound request body: $requestBody", [
            'batch_job_id' => $this->batch_job_id
        ]);

        $response = curl_exec($curl);
        $err = curl_error($curl);

        if ($err) {
            REDCap::logEvent(self::NCI_MODULE_LOG_NAME, $err);
            $this->module->log("An error occurred . $err", [
                'batch_job_id' => $this->batch_job_id
            ]);
            REDCap::email(implode(', ', $this->email_alert_to), $this->email_alert_from, 'NCICohortLink - Token and PIN Generator Network Error',  $err);
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
            $this->module->log("DATA ENTRY TRIGGER INITIATED");
            $this->is_DET = true;
            $this->startNewBatchJob();
        }
    }


}

