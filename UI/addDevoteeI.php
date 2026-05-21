<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/web_session.php';

$config_data = include dirname(__DIR__) . '/site_config.php';
$directoryName = getenv('KDMS_PATH_SEGMENT') ?: 'kdms';

$debug = false;

require_once __DIR__ . '/../Logic/clsDevoteeSearch.php';
require_once __DIR__ . '/../Logic/clsOptionHandler.php';

if ($debug) {
    var_dump($config_data);
}

$requestData = $_GET;
$response = null;
$is_key_available = false;
        $devotee_key = "";
        $devotee_type = "T";
        $devotee_first_name = "";
        $devotee_last_name = "";
        $devotee_gender = "";
        $devotee_dob = "";
        $devotee_id_type = "";
        $devotee_id_number = "";
        $devotee_address_1 = "" ; 
        $devotee_address_2 = "" ; 
        $devotee_station = "";
        $devotee_state = "" ; 
        $devotee_zip = "" ; 
        $devotee_country = "" ; 
        $devotee_email = "";
        $devotee_cell_phone_number = "";
        $devotee_status = "";
        $devotee_blacklisted = false;
        $joined_since  = "" ;
        $devotee_referral = "";
        $devotee_remarks = "";
        $comments  = "" ; 
        $devotee_seva_id = "";
        $devotee_accommodation_id = "";
        $devotee_photo = "";
        $devotee_id_image = "";
        $eventId = $config_data['event_id'];
        $userId = $_SESSION["LoginID"];
        $remarkSubmitted = false;

        if($debug) {echo "User ID: ", $userId; }

        //load accommodation options and available spots
        $loadAccommodation = new clsOptionHandler("Accommodation");
        $loadAccommodation->setEventId($eventId);
        $accommodations = $loadAccommodation->getOptions();
        unset($loadAccommodation);
        
        //load seva options and assigned devotee counts
        $loadSeva = new clsOptionHandler("Seva");
        $loadSeva->setEventId($eventId);
        $sevas = $loadSeva->getOptions();
        unset($loadSeva);
        $accommodations = is_array($accommodations) ? $accommodations : [];
        $sevas = is_array($sevas) ? $sevas : [];

        // Pre-populate devotee record in case of edit; otherwise reserve key for photo/ID before save (Phase 2).
        if (!empty($requestData['devotee_key'])) {
            $devotee_key=$requestData['devotee_key'];
            $is_key_available=true;
            $devoteeSearch = new clsDevoteeSearch($requestData);
            $response = $devoteeSearch->getDevoteeDetails($eventId);
            if (! is_array($response)) {
                $response = null;
            }

            if($debug){ echo "<br> response: "; var_dump($response); echo "<br> eventID: "; var_dump($eventId);}
        } else {
            require_once dirname(__DIR__) . '/api/config/database.php';
            require_once dirname(__DIR__) . '/api/Interface/devotees.php';
            $reserveDb = (new Database())->getConnection();
            $reserveDevotee = new Devotee($reserveDb);
            $devotee_key = $reserveDevotee->generateId();
        }

        if ($is_key_available) {
            //assign values
            if ($response !== null && is_array($response)) {
                if (! empty($response['Devotee_Key'])) {
                    $devotee_key = urldecode($response['Devotee_Key']); //"P1810142093"
                }

            if (!empty($response['Devotee_Type'])) {
                $devotee_type = urldecode($response['Devotee_Type']); // "p" "P";
            }

            if (!empty($response['Devotee_First_Name'])) {
                $devotee_first_name = urldecode($response['Devotee_First_Name']); // "Anil+6" ;
            }

            if (!empty($response['Devotee_Last_Name'])) {
                $devotee_last_name = urldecode($response['Devotee_Last_Name']); // "Gupta" 
            }

            if (!empty($response['Devotee_Gender'])) {
                $devotee_gender = urldecode($response['Devotee_Gender']); // "Gupta" 
            }

            if (!empty($response['Devotee_DOB'])) {
                $devotee_dob = urldecode($response['Devotee_DOB']); // "Gupta" 
            }

            if (!empty($response['Devotee_ID_Type'])) {
                $devotee_id_type = urldecode($response['Devotee_ID_Type']); // NULL     
            }

            if (!empty($response['Devotee_ID_Number'])) {
                $devotee_id_number = urldecode($response['Devotee_ID_Number']); // "" 
            }

            if (!empty($response['Devotee_Address_1'])) {
                $devotee_address_1 = urldecode($response['Devotee_Address_1']); //  "" 
            }

            if (!empty($response['Devotee_Address_2'])) {
                $devotee_address_2 = urldecode($response['Devotee_Address_2']); //  "" 
            }

            if (!empty($response['Devotee_Station'])) {
                $devotee_station = urldecode($response['Devotee_Station']); // "New+Delhi" 
            }

            if (!empty($response['Devotee_State'])) {
                $devotee_state = urldecode($response['Devotee_State']); //  "" 
            }

            if (!empty($response['Devotee_Zip'])) {
                $devotee_zip = urldecode($response['Devotee_Zip']); //  "" 
            }

            if (!empty($response['Devotee_Country'])) {
                $devotee_country = urldecode($response['Devotee_Country']); //  "" 
            }

            if (!empty($response['Devotee_Email'])) {
                $devotee_email = urldecode($response['Devotee_Email']); //  "4156227879" 
            }

            if (!empty($response['Devotee_Cell_Phone_Number'])) {
                $devotee_cell_phone_number = urldecode($response['Devotee_Cell_Phone_Number']); //  "4156227879" 
            }
            if (!empty($response['Devotee_Status'])) {
                $devotee_status = urldecode($response['Devotee_Status']); // "A" ;
                if($devotee_status=='B'){
                    $devotee_blacklisted=true;
                }
            }
            if (!empty($response['Joined_Since'])) {
                $joined_since = urldecode($response['Joined_Since']); //  "" 
            }
        
            if (!empty($response['Devotee_Referral'])) {
                $devotee_referral = urldecode($response['Devotee_Referral']); //  "" 
            }
                
            if (!empty($response['Devotee_Remarks'])) {
                $devotee_remarks = urldecode($response['Devotee_Remarks']); //  "" 
            }

            if (!empty($response['Comments'])) {
                $comments = urldecode($response['Comments']); //  "" 
            }
     
            if (!empty($response['Seva_ID'])) {
                $devotee_seva_id = urldecode($response['Seva_ID']); //  "" 
            }

            if (!empty($response['Devotee_ID_Image'])) {  // NULL
                $devotee_id_image = $response['Devotee_ID_Image'];
            }  

              if (!empty($response['Devotee_Photo'])) {  // NULL
                $devotee_photo = $response['Devotee_Photo'];             
            }

           if (!empty($response['Accomodation_Key'])) {
                $devotee_accommodation_id = urldecode($response['Accomodation_Key']); //  "" 
            }
            }
        }
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <title>
            KDMS (Add Devotee I)
        </title>
        <?php include_once __DIR__ . '/header.php'; ?>

    <script>
        window.kdmsRequestManagerUrl = <?= json_encode($config_data['webroot'] . 'Logic/requestManager.php', JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_SLASHES) ?>;
        window.kdmsWebRoot = <?= json_encode($config_data['webroot'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_SLASHES) ?>;
        var directoryName = <?= json_encode($directoryName, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
        var dataSaved = false;
    </script>
    <script src="../assets/js/main/add_devotee.js"></script>
</head>
<body class="">
    <link href="../assets/demo/demo.css" rel="stylesheet" />
        <div class="wrapper ">
            <?php
            include_once("nav.php");
            ?>

            <div class="main-panel">
                <div class="content">
                    <div class="container-fluid">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="card">
                                    <div class="card-header card-header-primary">
                                        <h4 class="card-title">Add Devotee Information</h4>
                                    </div>
                                    <div class="card-body">
                                        <?php if($devotee_status == 'B') { ?>
                                                <div>
                                                    <div class="blacklist_container" id="blsign">
                                                        <label
                                                            class="bmd-label-floating blacklist_label"
                                                            name="blacklist_label"
                                                            id="blacklist_label"
                                                            >
                                                            BLACKLISTED
                                                        </label>
                                                    </div>
                                                </div>
                                        <?php } ?>
                                        <div class="row" style="float: right;">
                                            <div class="col-sm-12 col-md-12 col-lg-12 col-xl-12" >
                                                <?php if ($devotee_key != "") { ?>
                                                        <div class="btn btn-light action-btn btn-med" data-toggle="modal" data-target="#AmenityModalLong">
                                                            Amenities
                                                        </div>
                                                        <div class="btn btn-light action-btn btn-med" data-toggle="modal" data-target="#ParticipationModalLong">
                                                            Participation
                                                        </div>
                                                        <div class="btn btn-light action-btn btn-med" data-toggle="modal" data-target="#RemarksModalLong">
                                                            Remarks
                                                        </div>
                                                        <!--Modal Window for Amenity Management-->
                                                        <?php include_once("modals/amenityMgmtModal.php"); ?>
                                                        <!--END - Modal Window for Amenity Management-->
                                                        <!--Modal Window for Participation Records-->
                                                        <?php include_once("modals/remarksModal.php"); ?>
                                                        <!--END - Modal Window for Participation Records-->
                                                        <!--Modal Window for Participation Records-->
                                                        <?php include_once("modals/participationRecordsModal.php"); ?>
                                                        <!--END - Modal Window for Participation Records-->
                                                    <?php } ?>
                                            </div>
                                        </div>
                                        <form  id="myForm">
                                            <div class="row">
                                                <div class="col-sm-6 col-md-6 col-lg-6 col-xl-6">
                                                    <div class="form-group">
                                                        <label class="bmd-label-floating">Devotee ID (non editable)</label>
                                                        <input type="text" name="devotee_key" id="devotee_key" class="form-control" readonly="true" value="<?php print_r($devotee_key); ?>">
                                                    </div>
                                                    <div id="qrcode" align="right" style="display:none;"></div>
                                                        <script type="text/javascript">
                                                                new QRCode(document.getElementById("qrcode"), {
                                                                text: document.getElementById("devotee_key").value,
                                                                width: 100,
                                                                height: 100,
                                                                colorDark : "#000000",
                                                                colorLight : "#ffffff",
                                                                correctLevel : QRCode.CorrectLevel.H
                                                                }
                                                            );
                                                        </script>
                                                    </div>
                                            </div>
                                            <div class="row" style="clear:both;">
                                                <div class="col-md-3" style="margin-top:36px;">
                                                    <div class="form-group" >
                                                        <label class="bmd-label-floating">First Name</label>
                                                        <input type="text" class="form-control" name="devotee_first_name" id="devotee_first_name" value="<?php print_r($devotee_first_name); ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-3" style="margin-top:36px;">
                                                    <div class="form-group">
                                                        <label class="bmd-label-floating">Last Name</label>
                                                        <input type="text" class="form-control" name="devotee_last_name" id="devotee_last_name" value="<?php print_r($devotee_last_name); ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-3" >
                                                    <div class="form-group">
                                                        <label class="bmd-label-floating">Gender</label>
                                                        <!-- <input type="text" class="form-control" name="devotee_gender" id="devotee_gender" value="<?php print_r($devotee_gender); ?>"> -->
                                                        <select type="text" class="form-control" name="devotee_gender" id="devotee_gender"  value="<?php print_r($devotee_gender); ?>">
                                                            <option value="M" <?php
                                                            if ($devotee_gender == "m"  || $devotee_gender == "M" || empty($devotee_gender)) {
                                                                print_r("selected");
                                                            }
                                                            ?>>Male</option>
                                                            <option value="F" <?php
                                                            if ($devotee_gender == "F"  || $devotee_gender == "f") {
                                                                print_r("selected");
                                                            }
                                                            ?>>Female</option>                                                            
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-3"style="margin-top:36px;">
                                                    <div class="form-group">
                                                        <label class="bmd-label-floating">Date of Birth</label>
                                                        <input type="text" class="form-control" name="devotee_dob" title="yyyy-mm-dd" id="devotee_dob" value="<?php print_r($devotee_dob); ?>">
                                                        
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-3">
                                                    <div class="form-group">
                                                        <label class="bmd-label-floating">ID Type</label>
                                                        <select type="text" class="form-control" name="devotee_id_type" id="devotee_id_type" value="<?php print_r($devotee_id_type); ?>">
                                                            <option value="none" <?php
                                                            if ($devotee_id_type == "none" || empty($devotee_id_type)) {
                                                                print_r("selected");
                                                            }
                                                            ?>>--Not Selected--</option>
                                                            <option value="Aadhaar" <?php
                                                            if ($devotee_id_type == "Aadhaar") {
                                                                print_r("selected");
                                                            }
                                                            ?>>Aadhaar</option>
                                                            <option value="DL" <?php
                                                            if ($devotee_id_type == "DL") {
                                                                print_r("selected");
                                                            }
                                                            ?>>DL</option>
                                                            <option value="Other" <?php
                                                            if ($devotee_id_type == "Other") {
                                                                print_r("selected");
                                                            }
                                                            ?>>Other Gov. ID</option>
                                                            <option value="PAN" <?php
                                                            if ($devotee_id_type == "PAN") {
                                                                print_r("selected");
                                                            }
                                                            ?>>PAN</option>
                                                            <option value="Passport" <?php
                                                            if ($devotee_id_type == "Passport") {
                                                                print_r("selected");
                                                            }
                                                            ?>>Passport</option>
                                                            <option value="Voter ID" <?php
                                                            if ($devotee_id_type == "Voter ID") {
                                                                print_r("selected");
                                                            }
                                                            ?>>Voter ID</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-3">
                                                    <div class="form-group" style="margin-top:62px;">
                                                        <label class="bmd-label-floating">ID Number</label>
                                                        <input type="text" class="form-control" name="devotee_id_number" id="devotee_id_number" value="<?php print_r($devotee_id_number); ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-3">
                                                    <div class="form-group" style="margin-top:62px;">
                                                        <label class="bmd-label-floating">Phone No.</label>
                                                        <input type="text" class="form-control" name="devotee_cell_phone_number" id="devotee_cell_phone_number" value="<?php print_r($devotee_cell_phone_number); ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-3">
                                                    <div class="form-group" style="margin-top:62px;">
                                                        <label class="bmd-label-floating">Email</label>
                                                        <input type="text" class="form-control" name="devotee_email" id="devotee_email" maxlength="44" value="<?php print_r($devotee_email); ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-3">
                                                    <div class="form-group">
                                                        <label class="bmd-label-floating">Devotee Type</label>
                                                        <select type="text" class="form-control" name="devotee_type" id="devotee_type" value="<?php print_r($devotee_type); ?>">
                                                            <option value="P" <?php
                                                            if ($devotee_type == "p"  || $devotee_type == "P") {
                                                                print_r("selected");
                                                            }
                                                            ?>>Permanent</option>
                                                            <option value="T" <?php
                                                            if ($devotee_type == "t" || $devotee_type == "T" || $devotee_type = "" || empty($devotee_type)) {
                                                                print_r("selected");
                                                            }
                                                            ?>>Temporary</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-3">
                                                    <!-- <div class="form-group" style="margin-top:26px;"> -->

                                                    <div class="form-group" >
                                                        <!-- replaced devotee station field by devotee status -->
                                                        <label class="bmd-label-floating">Devotee Status</label>
                                                        <select type="text" class="form-control" name="devotee_status" id="devotee_status" >
                                                            <option value="G" <?php
                                                            if ($devotee_status == "g"  || $devotee_status == "G" || empty($devotee_status)) {
                                                                print_r("selected");
                                                            }
                                                            ?>>Good</option>
                                                            <option value="A" <?php
                                                            if ($devotee_status == "a"  || $devotee_status == "A") {
                                                                print_r("selected");
                                                            }
                                                            ?>>Average</option>
                                                            <option value="D" <?php
                                                            if ($devotee_status == "d"  || $devotee_status == "D") {
                                                                print_r("selected");
                                                            }
                                                            ?>>Day Visitor</option>
                                                            <option value="S" <?php
                                                            if ($devotee_status == "s"  || $devotee_status == "S") {
                                                                print_r("selected");
                                                            }
                                                            ?>>Senior Citizen</option>
                                                            <option value="B" <?php
                                                            if ($devotee_status == "b"  || $devotee_status == "B") {
                                                                print_r("selected");
                                                            }
                                                            ?>>Black Listed</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-3">
                                                    <div class="form-group" style="margin-top:62px;">
                                                        <label class="bmd-label-floating">Referral</label>
                                                        <input type="text" class="form-control" name="devotee_referral" id="devotee_referral" value="<?php print_r($devotee_referral); ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-3">
                                                    <div class="form-group" style="margin-top:62px;">
                                                        <label class="bmd-label-floating">Joined Since</label>
                                                        <input type="text" class="form-control" name="joined_since" id="joined_since" value="<?php print_r($joined_since); ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label class="bmd-label-floating">Assigned Seva</label>

                                                        <select type="text" class="form-control" name="devotee_seva_id" id="devotee_seva_id" >
                                                            <?php
                                                            foreach ($sevas as $seva) {
                                                                print_r("<option value='" . $seva['Seva_Id'] . "'");
                                                                if (empty($devotee_seva_id)) {
                                                                    $devotee_seva_id = 'UN';
                                                                }
                                                                if ($devotee_seva_id == $seva['Seva_Id']) {
                                                                    print_r("selected");
                                                                }
                                                                //Print_r(">" . $seva['Seva_Description'] . " - " . $accommodation['Available_Count'] . "</option>");
                                                                Print_r(">" . urldecode($seva['Seva_Description']) . "</option>");
                                                            }
                                                            ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label class="bmd-label-floating">Accommodation</label>

                                                        <select type="text" class="form-control" name="devotee_accommodation_id" id="devotee_accommodation_id" >
                                                            <?php
                                                            foreach ($accommodations as $accommodation) {
                                                                print_r("<option value='" . $accommodation['accomodation_key'] . "'");
                                                                if (empty($devotee_accommodation_id)) {
                                                                    $devotee_accommodation_id = 'OWN';
                                                                }
                                                                if ($devotee_accommodation_id == $accommodation['accomodation_key']) {
                                                                    print_r("selected");
                                                                }
                                                                Print_r(">" . urldecode($accommodation['Accomodation_Name']) . " (" . $accommodation['Available_Count'] . " spaces) </option>");
                                                            }
                                                            ?>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <div class="form-group" >
                                                        <label class="bmd-label-floating">Address Line 1</label>
                                                        <input type="text" class="form-control" name="devotee_address_1" id="devotee_address_1" maxlength="99" value="<?php print_r($devotee_address_1); ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="form-group">
                                                        <label class="bmd-label-floating">Address Line 2</label>

                                                        <input type="text" class="form-control" name="devotee_address_2" id="devotee_address_2" maxlength="99" value="<?php print_r($devotee_address_2); ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="form-group">
                                                        <label class="bmd-label-floating">City / Station</label>
                                                        <input type="text" class="form-control" name="devotee_station" id="devotee_station" maxlength="99" value="<?php print_r($devotee_station); ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <div class="form-group" >
                                                        <label class="bmd-label-floating">State</label>
                                                        <input type="text" class="form-control" name="devotee_state" id="devotee_state" maxlength="24" value="<?php print_r($devotee_state); ?>">
                                                    </div>
                                                </div>

                                                <div class="col-md-4">
                                                    <div class="form-group">
                                                        <label class="bmd-label-floating">Zip/Pin Code</label>
                                                        <input type="text" class="form-control" name="devotee_zip" id="devotee_zip" value="<?php print_r($devotee_zip); ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <div class="form-group">
                                                        <label class="bmd-label-floating">Country</label>
                                                        <input type="text" class="form-control" name="devotee_country" id="devotee_country" value="<?php print_r($devotee_country); ?>">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-12">
                                                    <div class="form-group">
                                                        <label>Feedback/Comments</label>
                                                        <div class="form-group">
                                                            <textarea class="form-control" rows="2" name="comments" id="comments"> <?php print_r($comments); ?></textarea>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <input type="hidden" name="requestType" id="requestType" value="upsertDevotee">
                                            <input type="hidden" name="eventId" id="eventId" value="<?php echo $eventId; ?>">
                                            <button type="reset" class="btn btn-success pull-right">Cancel</button>                    
                                            <button type="button" class="btn btn-success pull-right" onclick="saveFormData('#myForm', 0); return false;">Save and Exit</button>
                                            <?php
                                            $print_count_txt="";
                                            if(is_array($response) && !empty($response['print_count'])){
                                                $print_count_txt="(".$response['print_count'].")";
                                            }
                                            if(!$devotee_blacklisted){
                                                //No of cards printed for this event 
                                                $flagp=-1;
                                                $pcount=0;
                                                $classBtn="btn-success";
                                                if(is_array($response) && !empty($response['print_count'])&& $response['print_count']>=1){
                                                    $flagp=-2;
                                                    $pcount=$response['print_count'];
                                                    $classBtn="btn-danger";
                                                }
                                            ?>
                                            <button type="button" data-pcount="<?=$pcount;?>" class="btn <?=$classBtn;?> btn-sgc pull-right" onclick="saveFormData('#myForm', <?= $flagp?>); return false;">Save and Generate Card <?=$print_count_txt;?></button>
                                            <?php } ?>
                                            <button type="button" class="btn btn-success pull-right" onclick="saveFormData('#myForm', 1); return false;" >Save</button>
                                        </form>
                                        <div class="clearfix"></div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <div class="card card-profile card-devotee-image-profile system-camera-layout">
                                    <div class="card-body photo-preview">
                                        <?php
                                        if ($devotee_photo == "") {
                                            echo '<div  id="photo2"><img class="devoteeImageStatic" src="../assets/img/faces/devotee.ico" alt="devotee image" height="200px" width="220px"></img></div>';
                                        } else {
                                            echo '<div  id="photo2"><img class="devoteeImage" id="devoteeImage" src="data:image/jpeg;base64,' . $devotee_photo . '" alt="devotee image"></img></div>';
                                        }
                                        ?> 
                                    </div>
                                    <i id="zoomInDevoteeImage" class="material-icons resetScaling">zoom_in</i>
                                    <i id="zoomOutDevoteeImage" class="material-icons resetScaling">zoom_out</i>
                                </div>
                                <div class="card card-profile card-devotee-image-profile system-camera-layout">
                                    <!-- Camera image -->
                                    <div class="row">
                                        <div class="col-xl-6 col-lg-12 col-md-12 col-sm-12 videoliveplay video-live-preview videoliveplay-left">
                                            <video class="photoImage" id="video" width="180" height="230" autoplay></video>
                                        </div>
                                        <div class="col-xl-6 col-lg-12 col-md-12 col-sm-12 videoliveplay videoliveplay-right">
                                            <canvas class="photoCanvas" id="canvas" ></canvas>
                                            <div id="photo"></div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col videoliveplay">
                                            <label class="cameraFileInput" for="cameraFileInput">
                                                <span class="btn upload-photo">Upload</span>
                                                <span class="btn open-camera">Open camera</span>
                                                <input
                                                    id="cameraFileInput"
                                                    type="file"
                                                    accept="image/*"
                                                    capture="environment"
                                                />
                                            </label>
                                            <button id="click-pic" class="btn btn-danger">
                                                Capture
                                            </button>                            
                                            <button id="upload-pic" type="button" class="btn btn-primary">Save</button>
                                            <input type="hidden" id="devotee_key_modal" name="devotee_key_modal" value="<?php print_r($devotee_key); ?>">
                                        </div>
                                    </div>
                                    <!-- end of camera image -->
                                </div>

                                <div class="card card-profile mobile-image-devotee-photo">
                                    <label class="cameraFileInput" for="cameraMobilePhotoFileInput">
                                        <div class="card-body" id="photo-mobile-preview_div">
                                            <?php
                                            if ($devotee_photo == "") {
                                                echo '<img class="devoteeImageStatic" src="../assets/img/faces/devotee.ico" alt="devotee image" height="200px" width="220px"></img>';
                                            } else {
                                                echo '<img class="devoteeImage" id="devoteeImage" src="data:image/jpeg;base64,' . $devotee_photo . '" alt="devotee image"></img>';
                                            }
                                            ?>
                                        </div>
                                        <!-- The hidden file `input` for opening the native camera -->
                                        <input
                                            id="cameraMobilePhotoFileInput"
                                            type="file"
                                            accept="image/*"
                                            capture="environment"
                                        />
                                        Click on above section to upload devotee Image.
                                    </label>
                                </div>
                                <div class="card card-profile">
                                    <label class="cameraFileInput" for="cameraIDFileInput">
                                        <div class="card-body" id="photo-id-preview_div">
                                            <?php
                                            if ($devotee_id_image == "") {
                                                echo '<img class="photo-id-preview" src="../assets/img/faces/doc.png" alt="devotee ID" height="350px" width="200px"></img>';
                                            } else {
                                                echo '<img class="photo-id-preview" src="data:image/jpeg;base64,' . $devotee_id_image . '" alt="devotee ID" height="400px" width="200px"></img>';
                                            }
                                            ?>
                                        </div>
                                        <!-- The hidden file `input` for opening the native camera -->
                                        <input
                                            id="cameraIDFileInput"
                                            type="file"
                                            accept="image/*"
                                            capture="environment"
                                        />
                                        Click on above section to upload document ID.
                                    </label>
                                </div>
                                </div>
                            </div>
                            <?php if ($is_key_available && $devotee_key !== '') { ?>
                            <div class="col-md-4">
                                <div class="card" id="dedup-hints-card">
                                    <div class="card-header card-header-info">
                                        <h4 class="card-title">Possible duplicates</h4>
                                        <p class="card-category">Read-only hints (Phase 2)</p>
                                    </div>
                                    <div class="card-body">
                                        <div id="dedup-hints-loading">Loading…</div>
                                        <ul id="dedup-hints-list" class="list-unstyled" style="display:none;"></ul>
                                        <p id="dedup-hints-empty" style="display:none;">No likely duplicates found.</p>
                                        <button type="button" class="btn btn-sm btn-warning" id="dedup-btn-merge" style="display:none;">Merge selected into this record</button>
                                    </div>
                                </div>
                            </div>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<!--   Core JS Files   -->
<?php include_once("scriptJS.php") ?>
<?php if ($is_key_available && $devotee_key !== '') {
    $dedupApi = htmlspecialchars($config_data['api_dir'] ?? '../api/', ENT_QUOTES, 'UTF-8');
    $dedupEvent = htmlspecialchars((string) $eventId, ENT_QUOTES, 'UTF-8');
    $dedupKey = htmlspecialchars($devotee_key, ENT_QUOTES, 'UTF-8');
?>
<script>
(function () {
  const apiBase = <?php echo json_encode(rtrim($config_data['api_dir'] ?? '../api/', '/') . '/'); ?>;
  const devoteeKey = <?php echo json_encode($devotee_key); ?>;
  const eventId = <?php echo json_encode($eventId); ?>;
  const loading = document.getElementById('dedup-hints-loading');
  const list = document.getElementById('dedup-hints-list');
  const empty = document.getElementById('dedup-hints-empty');
  const mergeBtn = document.getElementById('dedup-btn-merge');
  const selected = new Set();

  fetch(apiBase + 'dedupHints.php?devotee_key=' + encodeURIComponent(devoteeKey) + '&eventId=' + encodeURIComponent(eventId), { credentials: 'same-origin' })
    .then(r => r.json())
    .then(data => {
      loading.style.display = 'none';
      const matches = (data && data.matches) ? data.matches.filter(m => m.devotee_key !== devoteeKey && m.action !== 'new') : [];
      if (!matches.length) {
        empty.style.display = 'block';
        return;
      }
      list.style.display = 'block';
      matches.forEach(m => {
        const li = document.createElement('li');
        li.className = 'mb-2';
        const id = 'dedup-cb-' + m.devotee_key;
        li.innerHTML = '<label><input type="checkbox" id="' + id + '" value="' + m.devotee_key + '"> ' +
          m.devotee_key + ' (score ' + m.score + ', signal ' + m.signal + ')</label>';
        list.appendChild(li);
        li.querySelector('input').addEventListener('change', (e) => {
          if (e.target.checked) selected.add(m.devotee_key);
          else selected.delete(m.devotee_key);
          mergeBtn.style.display = selected.size ? 'inline-block' : 'none';
        });
      });
    })
    .catch(() => { loading.textContent = 'Could not load duplicate hints.'; });

  mergeBtn.addEventListener('click', () => {
    if (!selected.size || !confirm('Merge selected records into ' + devoteeKey + '? This cannot be undone easily.')) return;
    fetch(apiBase + 'adminMergeDevotees.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ base_devotee_key: devoteeKey, tbm_devotee_keys: Array.from(selected), eventId: eventId })
    })
      .then(r => r.json())
      .then(data => {
        if (data.status) {
          alert('Merged. Survivor: ' + data.Devotee_Key);
          location.reload();
        } else {
          alert(data.message || 'Merge failed');
        }
      })
      .catch(() => alert('Merge request failed'));
  });
})();
</script>
<?php } ?>
<script src="../assets/js/pages/capture.js"></script>
<script src="../assets/js/pages/captureID.js"></script>

</body>

</html>
