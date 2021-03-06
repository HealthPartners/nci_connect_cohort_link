<?php
ignore_user_abort(true);//Helps to Run the script after client abort
set_time_limit(1800); // Max of 30min batch script run to avoid unlimited run time settings
//Simple routing process to execute the function for service requested

if (isset($_GET['action']) && $_GET['action'] == "start_batch" &&  isset($_GET['passcode'])) {
    $rest_call_secret = $module->getProjectSetting("apimanager-rest-call-secret-key");
    header('Content-Type: application/json');
    if ( isset($_GET['passcode']) &&  $_GET['passcode'] == $rest_call_secret) {
        $module->log("New API REST Call Made");
        $batchstatus = $module->startTokenAndPINGenBatchJob();

        if ($batchstatus) {
            sendResponse("New Batch Job Initated");
        } else {
            sendResponse("The existing batch job still in progress, not allowed to run new batch job");
        }
    } else {
        sendResponse("Invalid passcode for REST call invocation");
    }
} else if (isset($_GET['action']) && $_GET['action'] == "DET" && isset($_GET['passcode'])){
       $rest_call_secret = $module->getProjectSetting("apimanager-rest-call-secret-key");
       $project_id =$_POST['project_id'];
       $username  =$_POST['username'];
       $record = $_POST['record'];
       $instrument = $_POST['instrument'];
       $event_id = $_POST['event_id'];
       $group_id = $_POST['group_id'];
       $repeat_instance = $_POST['repeat_instance'];
       if ( isset($_GET['passcode']) &&  $_GET['passcode'] == $rest_call_secret && isset($_POST['project_id']) &&  isset($_POST['record']) ) {
           $module->redcap_data_entry_form_DET($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance);
           sendResponse("New DET Job Initiated");
       } else {
           sendResponse("New DET Job not Initiated with required params");
       }
} else {
    echo "<br><b>NCI Connect Cohort Link API Manager</b> - REST Services Base URL : " . $module->getUrl("apimanager.php" , $noAuth=true , $useApiEndpoint=true) ."  </br></br> 1. Token and PIN Generation Batch Job Initiation : GET params : action = start_batch and passcode=[Your REST Call Secret] </br></br>  example : http://localhost/redcap/api/?type=module&prefix=nci_connect_cohort_link&page=apimanager&pid=". $module->getProjectId() ."&NOAUTH&action=start_batch&passcode=123456789</h5></br></br>
          2. DET Service URL : GET params : action = DET and passcode=[Your REST Call Secret] </br></br>example :  http://localhost/redcap/api/?type=module&prefix=nci_connect_cohort_link&page=apimanager&pid=". $module->getProjectId() ."&NOAUTH&action=DET&passcode=123456789</h5>  ";
}

function sendResponse($message){
    echo "{\"message\" : \"".$message."\"}" ;
}


?>