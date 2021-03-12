<?php
// Set the namespace defined in your config file
namespace HealthPartners\Institute\NCIConnectCohortLink;
//Import Service 
require_once("NCIConnectTokenAndPinGenService.php");
require_once("IHCSSendDeIdentifiedDataToNCIService.php");

use HealthPartners\Institute\NCIConnectCohortLink\Service\NCIConnectTokenAndPinGenService as  NCIConnectTokenAndPinGenService;
use HealthPartners\Institute\NCIConnectCohortLink\Service\IHCSSendDeIdentifiedDataToNCIService as  IHCSSendDeIdentifiedDataToNCIService;
use Exception;
use REDCap;

// NCIConnectTokenAndPinGenerator module class, which must extend AbstractExternalModule 
class NCIConnectCohortLink extends \ExternalModules\AbstractExternalModule {
     private $nciTokenAndPINGenService;
     private $sendDeIdentifiedDataToNCIService;

     public function __construct(){
         parent::__construct();
         $this->nciTokenAndPINGenService = new NCIConnectTokenAndPinGenService($this);
         $this->sendDeIdentifiedDataToNCIService = new IHCSSendDeIdentifiedDataToNCIService($this);
     }

     // This function helps to force stop the current running job after the current running batch 
     public function forceBatchStop(){
         return $this->nciTokenAndPINGenService->forceBatchStop();
     }

     public function startTokenAndPINGenBatchJob(){
          return $this->nciTokenAndPINGenService->startNewBatchJob();
     }


     //This checks if already any batch job currently in progress for this job
     public function isBatchJobLocked(){
         return $this->nciTokenAndPINGenService->isBatchJobLocked();

     }

     //Force Clear Batch Job Lock
     public function forceClearBatchJobLock(){
         return $this->nciTokenAndPINGenService->forceClearBatchJobLock();
     }

     public function getBatchDataItem($item){
         return $this->nciTokenAndPINGenService->getBatchDataItem($item);
     }


     /* This is disable to support API based cron schedule option
      ///REDCap Corn Job Scheduler Trigger Function
     public function tokengeneratorscheduler($cronAttributes){
          $originalPid = $_GET['pid'];
          foreach($this->framework->getProjectsWithModuleEnabled() as $localProjectId){
              $is_cron_job_set = $this->getProjectSetting("is-cron-for-token-gen-enabled");
              if ( isset($is_cron_job_set) && $is_cron_job_set == "1") {
                  // This automatically associates all log statements with this project.
                  $_GET['pid'] = $localProjectId;
                  REDCap::logEvent(self::NCI_MODULE_LOG_NAME, "CORN JOB STARTED ", NULL,NULL,NULL, $localProjectId);
                  $this->nciTokenAndPINGenService->clearCurrentBatchJobStatus();
                  $this->nciTokenAndPINGenService->startTokenAndPINGenBatchJob();
                  // Put the pid back the way it was before this cron job (likely doesn't matter, but is good housekeeping practice)
              }
          }
          $_GET['pid'] = $originalPid;
     } */


    // This method will be called by the redcap_data_entry_form hook
    function redcap_data_entry_form_DET($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance)
    {
	   $this->nciTokenAndPINGenService->redcap_data_entry_form_top($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance);
    }


    /**
     * This method will initiate the send de-identified data to NCI batch process
     */
    function startSendDeIdentifyDataToNCIBatchJob() {
       return $this->sendDeIdentifiedDataToNCIService->startNewBatchJob();
    }

}
 