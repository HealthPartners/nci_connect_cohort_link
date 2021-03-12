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
        

        $data = REDCap::getData($this->module->getProjectId() , 'array', NULL, $this->fields_send_with_token_request, NULL, NULL, false, false, false, $this->record_filter_logic, false, false);

        return $this->batch_job_id;
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
}
