<?php

namespace HealthPartners\Institute\NCIConnectCohortLink\Service;

use Exception;
use REDCap;

class IHCSDataSyncService
{
    //To Store Primay External Module Object
    private $module;
    private $nci_connect_api_key; // To hold NCI API key
    private $nci_connect_api_endpoint; // To hold NCI API endpoint
    private $record_filter_logic; // to hold record filter condition which helps to only include valid studyids for api request
    private $batch_size; // to hold number of records send part of each API request
    private $email_alert_from; // to hold from email address for email alert when error occurred
    private $email_alert_to; // to hold comma sepeated to email address for email alert when error occurred
    private $curr_proj_record_id_field;
    private $fields_send_with_token_request; // to hold list of field(s) send part of API request - array.
    private $ncitoken;
    private $datasync_field_list;

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
            $limit = 100;
            $page = 1;
            // Loop through until Limit and dataSize value differnce which means no other data to fetch through pageination
            do {
                unset($responseDataArray);
                $responseDataArray = array();
                $responseDataArray  = $this->makeWebServiceRequest($type, $limit, $page);
                print_r($responseDataArray);
                //Write data into REDCap
                $this->writeData($responseDataArray, $redcap_conceptid_map, $recordStudyIdTokenMapArray);
                $page++;
            } while (count($responseDataArray) > 0 && $responseDataArray["code"] == 200 && $responseDataArray["limit"] == $responseDataArray["dataSize"]);
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
                        $writeTempArray[$redcap_var] = $record[$conceptid];
                        $isUpdate = true;
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
        print_r($responseJSON);
        $response = REDCap::saveData($this->module->getProjectId(), 'json', $responseJSON, 'overwrite', NULL, NULL, NULL, TRUE);
        $this->module->log("Send Deidentified Data Status Saved To REDCap", ['batch_job_id' => $this->batch_job_id]);
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
        return $out_array;
    }

    private function iniGenerator()
    {
        $currnet_nci_env = $this->module->getProjectSetting("nciconnect-env"); // 1=DEV & 2=PROD
        if (isset($currnet_nci_env) && $currnet_nci_env == "1") {
            $this->nci_connect_api_key = $this->module->getProjectSetting("dev-nciapikey");
            $this->nci_connect_api_endpoint = $this->module->getProjectSetting("dev-api-server-get-participant-data-url");
        } else if (isset($currnet_nci_env) && $currnet_nci_env == "2") {
            $this->nci_connect_api_key = $this->module->getProjectSetting("prod-nciapikey");
            $this->nci_connect_api_endpoint = $this->module->getProjectSetting("prod-api-server-get-participant-data-url");
        }

        if (!empty($this->module->getProjectSetting("nci-token-store-field"))) {
            $this->ncitoken = $this->module->getProjectSetting("nci-token-store-field");
        }

        if (!empty($this->module->getProjectSetting("studyid-field-batch-process"))) {
            $this->inputstudyid = $this->module->getProjectSetting("studyid-field-batch-process");
        }

        if (!empty($this->module->getProjectSetting("datasync-record-filter-logic"))) {
            $this->record_filter_logic = $this->module->getProjectSetting("datasync-record-filter-logic");
        }

        if (!empty($this->module->getProjectSetting("datasync-field-list"))) {
            $this->datasync_field_list = $this->module->getProjectSetting("datasync-field-list");
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
}
