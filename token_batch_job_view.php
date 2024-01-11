<?php
// Set the namespace defined in your config file
namespace HealthPartners\Institute\NCIConnectCohortLink;

//This is used to check if already import process currenlty running
$is_batch_locked = $module->isBatchJobLocked();
$last_set_batch_log = $module->queryLogs("select log_id, timestamp, message, batch_job_id order by log_id desc limit 10000" ,[]);
$curr_batch = 0;
$total_num_batch=0;
$total_num_record_processed=0;
if ($is_batch_locked===true) {
  $curr_batch = $module->getBatchDataItem("CURR_BATCH");
  $total_num_batch = $module->getBatchDataItem("TOTAL_NUM_BATCH");
  $total_num_record_processed=$module->getBatchDataItem("TOTAL_NUM_RECORD_PROCESSED");
}
?>
<div class="card" style="background-color: #F0F0F0!important;">
  <div class="card-header">
  <span style="color:#000000;font-size:18px"> NCI Batch Job Manager - Token/PIN Generator</span>
     <div class=" float-right">
        
        <button class="btn btn-sm btn-link mr-3" <?php if ($is_batch_locked===false){?>disabled<?php } ?>   onclick="window.location.reload()">Get Status Update</button>
        <button class="btn btn-sm btn-outline-secondary mr-3 "  <?php if ($is_batch_locked===false){?>disabled<?php } ?>  onclick="this.disabled=true;NCIConnectTokenAndPinGenerator.doBatchCommand('force_stop')">Force Stop</button>
        <button class="btn btn-sm btn-outline-secondary mr-3 "  <?php if ($is_batch_locked===false){?>disabled<?php } ?>  onclick="this.disabled=true;NCIConnectTokenAndPinGenerator.doBatchCommand('force_clear')">Force Clear</button>
        <button class="btn btn-sm btn-outline-primary mr-3 "  <?php if ($is_batch_locked===true){?>disabled<?php } ?>  onclick="this.disabled=true;this.innerHTML='started..';NCIConnectTokenAndPinGenerator.doBatchCommand('start_batch')">Start</button>
     </div>
  </div>
  <div class="card-body" style="background-color: #000000!important;" > 
      <?php if ($is_batch_locked===true){?>
      <div class="progress" style="height: 5px;">
        <div class=" bg-success progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: <?php echo ($curr_batch/$total_num_batch)*100 ; ?>%;" aria-valuenow="25" aria-valuemin="0" aria-valuemax="100"></div>
      </div>
      <samp><span style="color:#ffff23"> <?php echo $curr_batch ?> out of <?php echo $total_num_batch ?> batch completed</span></samp>
      <p><samp  > <span style="color:#ffff23"> Total records completed  : <?php echo $total_num_record_processed ?> </span> </samp></p>
      <script type="text/javascript">
        setTimeout(function () { location.reload(); }, 10000);
      </script>
      <?php } ?> 
  </div>
  <div class="card-footer  " style="background-color: #000000!important;color:#ffffff" >
     <div id="batch-job-log-console">
          <?php
              while($row = $last_set_batch_log->fetch_assoc()){
                echo "<li style=\"list-style-type:none;\"> " . htmlspecialchars($row["log_id"], ENT_QUOTES) . " : " . htmlspecialchars($row["batch_job_id"], ENT_QUOTES) . " : " .  htmlspecialchars($row["timestamp"], ENT_QUOTES) . " : " .  htmlspecialchars($row["message"] , ENT_QUOTES) . "</li>";
              }
          ?>
     </div>
  </div>
</div>
<script type="text/javascript">
  // Single global scope object containing all variables/functions
  var NCIConnectTokenAndPinGenerator = {};
  NCIConnectTokenAndPinGenerator.batchServiceURL = <?=json_encode($module->getUrl('batchservice.php'))?>;
</script>
<script type="text/javascript" src="<?=$module->getUrl('js/modulefunctions.js')?>"></script>