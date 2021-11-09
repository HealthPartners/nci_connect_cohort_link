<?php
ignore_user_abort(true);//Helps to Run the script after client abort 
set_time_limit(1800); // Max of 30min batch script run to avoid unlimited run time settings 
//Simple routing process to execute the function for service requested
if (isset($_POST['action']) && $_POST['action'] == "start_batch" ) {
    //$module->setProjectSetting("is_import_currently_inprogress", "N");
    //$batchstatus = $module->startBatchProcess();
    //$module->forceClearBatchJobLock();
    sleep(4);
    $batchstatus = $module->startTokenAndPINGenBatchJob();
    header('Content-Type: application/json');
    if ($batchstatus) {

        sendResponse("New Batch Job Initated");
    } else {
        sendResponse("The existing batch job still in progress, not allowed to run new batch job");
    }
} else if (isset($_POST['action']) && $_POST['action'] == "force_stop") {
    $batchstatus = $module->forceBatchStop();
    if ($batchstatus) {
        sendResponse("The Batch Force Stop Initated");
    } else {
        sendResponse("Opps!..The Batch Force Stop Initation Failed");
    }
} else if (isset($_POST['action']) && $_POST['action'] == "force_clear") {
    $module->forceClearBatchJobLock();
    sendResponse("The Batch Force Clear Initated");
}
function sendResponse($message){
     echo "{\"message\" : \"".$message."\"}" ;
}
