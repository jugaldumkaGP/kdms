<?php
$debug = false;
$config_data = include dirname(__DIR__) . '/site_config.php';
require_once dirname(__DIR__) . '/includes/kdms_access_helpers.php';
$navCanDevoteeSearch = kdms_session_has_asset('KD-DVT-SCR');
$navCanKitchen = kdms_session_has_asset('KD-KITCHEN');
?>
<div class="sidebar" data-color="purple" data-background-color="white" data-image="../assets/img/sidebar-1.jpg">
  <!--
      Tip 1: You can change the color of the sidebar using: data-color="purple | azure | green | orange | danger"

      Tip 2: you can also add an image using data-image tag
  -->


  <div class="logo">
    <a href="#" onclick=refreshSession() class="simple-text kdms-title logo-normal">
      <h3>KDMS</h3>
        <!--<br> -->
       <b class="event-title-sidebar"> <?=$_SESSION["eventDesc"];?> </b>
    </a>

      <script type="text/javascript">
          function refreshSession(){
              //alert("This doesn't do anything. You will need to somehow kill your session, if you are trying to load the next event. Adding log out functionality will fix this issue!");

              <?php
              //session_unset();
              //header("Location: /index.php");
              if ($debug) {echo "current session ID: ", session_id(), "<br>", "session_status: ", session_status(), "<br>";}
              ?>
              //location.reload();
          }
      </script>

  </div>

  <div class="sidebar-wrapper">
    <ul class="nav">
      <li class="nav-item active  ">
        <a class="nav-link" href="./index.php">
          <i class="material-icons">dashboard</i>
          <p>Dashboard</p>
        </a>
      </li>
      <li class="nav-item ">
        <a class="nav-link" href="./registration.php">
          <i class="material-icons">camera_alt</i>
          <p>Photo/ID Capture</p>
        </a>
      </li>
       <li class="nav-item ">
        <a class="nav-link" href="./addDevoteeI.php">
          <i class="material-icons">person_add</i>
          <p>Register New Devotee</p>
        </a>
      </li>     
      <?php if ($navCanDevoteeSearch): ?>
      <li class="nav-item ">
        <a class="nav-link" href="./devoteeSearchResult.php?mode=CUS&key=">
          <i class="material-icons">search</i>
          <p>Search Devotees</p>
        </a>
      </li>

      <li class="nav-item ">
        <a class="nav-link" href="./devoteeSearchResult.php?mode=SET&key=CTP">
          <i class="material-icons">print</i>
          <p>Devotee Cards for Printing</p>
        </a>
      </li>
      <li class="nav-item ">
        <a class="nav-link" href="./devoteeSearchResult.php?mode=SET&key=TMP">
          <i class="material-icons">print</i>
          <p>Day Visitor Print Queue</p>
        </a>
      </li>
      <li class="nav-item ">
        <a class="nav-link" href="./devoteeSearchResult.php?mode=SET&key=RPC">
          <i class="material-icons">print</i>
          <p>Recently Printed Cards</p>
        </a>
      </li>
      <li class="nav-item ">
        <a class="nav-link" href="./devoteeMergeUtility.php">
          <i class="material-icons">merge_type</i>
          <p>Merge Duplicate Records</p>
        </a>
      </li>
      <?php /* KDMS OCR nav removed Phase 6/7 — OCR integrated into Add Devotee form (addDevoteeI.php). */ ?>
      <?php endif; ?>
      <?php if ($navCanKitchen): ?>
      <li class="nav-item ">
        <a class="nav-link" href="./kitchenDashboard.php">
          <i class="material-icons">restaurant</i>
          <p>Kitchen Dashboard</p>
        </a>
      </li>
      <?php endif; ?>
    </ul>
  </div>
</div>
<?php include_once("topNav.php"); ?>
<script src="../assets/js/jquery-3.2.1.min.js"></script>
<script>
      $(document).ready(function() {
      $('#search-data').unbind().keyup(function(e) {
          var value = $(this).val();
          if (value.length>2) {
              searchData(value);
          } else {
               $('#search-result-container').hide();
          }
      });
      });

      function searchData(val){
          var reqType = "dynamicSearchDevotee";
      	$('#search-result-container').show();
      	$('#search-result-container').html('<div><img src="../assets/img/preloader.gif" width="50px;" height="50px"> <span style="font-size: 20px;">Please Wait...</span></div>');
        $.post('<?=$config_data['webroot'];?>Logic/requestManager.php',{'key': val, 'requestType': reqType}, function(data){
      		if(data != ""){
      			$('#search-result-container').html(data);
                    // add some css to scroll/view all data
                    $('.sidebar').css({'overflow-y':'scroll'});
                    $('.sidebar-wrapper').css({'overflow-y':'scroll'});
                }else{
      		$('#search-result-container').html("<div class='search-result'> Please wait.. </div>");
            }
      	}).fail(function(xhr, ajaxOptions, thrownError) { //any errors?
            $('#search-result-container').html('');
      	   alert(thrownError); //alert with HTTP error
      	});
      }
  </script>
