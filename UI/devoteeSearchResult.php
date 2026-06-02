<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/web_session.php';

$config_data = include dirname(__DIR__) . '/site_config.php';

include_once 'header.php';
include_once dirname(__DIR__) . '/Logic/clsDevoteeSearch.php';
include_once dirname(__DIR__) . '/Logic/clsOptionHandler.php';

$eventId = $config_data['event_id'];

//if($debug){var_dump( $_GET);}
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <title> KDMS (Available Devotee Records) </title>

        <script>

            function _(el) {
                return document.getElementById(el);
            }


            //javascript function for ajax call
            function submitSearch(formId, flag) {

                searchForm = document.getElementById(formId);
                var I = 0;
                var searchString = ""
                for (I = 0; I < searchForm.length; I++) {
                    if (searchForm[I].value != "") {
                        //alert(searchForm[I].id + ": " + searchForm[I].value);
                        searchString = searchString + searchForm[I].id + "=" + encodeURI(searchForm[I].value) + ",";
                    }
                }
                //alert(searchString);

                if (searchString.length > 1) {
                    window.location = "./devoteeSearchResult.php?mode=CUS&key=" + searchString.substr(0, searchString.length - 1);
                } else {
                    alert("Please specify a search criteria!");
                }
            }

            function printQueueReturnKey() {
                var q = new URLSearchParams(window.location.search);
                var key = q.get('key');
                if (key === 'TMP' || key === 'RPC' || key === 'CTP') {
                    return key;
                }
                return 'CTP';
            }

            function submitPrint(formId, flag) {
                printForm = document.getElementById(formId);
                var I = 0;
                var printString = ""
                for (I = 0; I < printForm.length; I++) {
                    if (printForm[I].value != "") {
                        //alert(searchForm[I].id + ": " + searchForm[I].value);
                        if (printForm[I].type == 'checkbox' && printForm[I].checked) {
                            printString = printString + printForm[I].value + ",";
                        }
                    }
                }

                if (printString.length > 1) {

                    // if query string url has key="TMP" then open rptCardsPrintTemp.php file #

                    const queryParams = new URLSearchParams(window.location.search);

                    if (queryParams.get('key') === 'TMP') {
                        window.open("./rptCardsPrintTemp.php?key=" + printString.substr(0, printString.length - 1) + "&mode=PCD");
                    } else {
                        // If not, open rptCardsPrint.php file
                        window.open("./rptCardsPrint.php?key=" + printString.substr(0, printString.length - 1) + "&mode=PCD");
                    }

                    // window.open("./rptCardsPrint.php?key=" + printString.substr(0, printString.length - 1) + "&mode=PCD");
                    //window.location.assign("./devoteeSearchResult.php?mode=SET&key=CTP");

                    //if(confirm("Card printed successfully?")){
                    $.ajax({
                        url: '<?=$config_data['webroot']?>Logic/requestManager.php',
                        type: 'POST',
                        data: {'devotee_key': printString.substr(0, printString.length - 1), 'requestType': "removeFromPrintQueue",'eventId':'<?= $eventId;?>'},
                        async: false,
                        success: function (response) {

                            var r = JSON.parse(response);

                            if (r['flag'] == true) {
                                alert("Card removed from the printing queue!");
                                window.location.assign("./devoteeSearchResult.php?mode=SET&key=" + printQueueReturnKey());
                            } else {
                                alert(r['message']);
                                updateSuccess = false;
                            }
                        }
                    });
                    //            }

                } else {
                    alert("Please select a card to print!");
                }
            }

            function removePrint(formId, flag) {
                printForm = document.getElementById(formId);
                var I = 0;
                var printString = ""
                for (I = 0; I < printForm.length; I++) {
                    if (printForm[I].value != "") {
                        //alert(searchForm[I].id + ": " + searchForm[I].value);
                        if (printForm[I].type == 'checkbox' && printForm[I].checked) {
                            printString = printString + printForm[I].value + ",";
                        }
                    }
                }

                if (printString.length > 1) {

                    //window.open("./rptCardsPrint.php?key=" + printString.substr(0, printString.length - 1) + "&mode=PCD");
                    //window.location.assign("./devoteeSearchResult.php?mode=SET&key=CTP");

                    //if(confirm("Card printed successfully?")){
                    $.ajax({
                        url: '<?=$config_data['webroot']?>Logic/requestManager.php',
                        type: 'POST',
                        data: {'devotee_key': printString.substr(0, printString.length - 1), 'requestType': "removeFromPrintQueue", 'eventId': '<?= $eventId; ?>'},
                        async: false,
                        success: function (response) {

                            var r = JSON.parse(response);

                            if (r['flag'] == true) {
                                alert("Card removed from the printing queue!");
                                window.location.assign("./devoteeSearchResult.php?mode=SET&key=" + printQueueReturnKey());
                            } else {
                                alert(r['message']);
                                updateSuccess = false;
                            }
                        }
                    });
                    //            }

                } else {
                    alert("Please select a card to print!");
                }
            }

            function checkAll()
            {
                var checktoggle = false;
                var checkboxes = new Array();
                if (document.getElementById("headerCheck").checked) {
                    checktoggle = true;
                }
                checkboxes = document.getElementsByTagName('input');
                for (var i = 0; i < checkboxes.length; i++) {
                    if (checkboxes[i].type == 'checkbox') {
                        checkboxes[i].checked = checktoggle;
                    }
                }
            }

            function validateInput() {
                return true;
            }
        </script>
    </head>

    <body class="">

        <div class="wrapper ">
            <?php
            include_once("nav.php"); ?>
            <div class="main-panel">
                <!-- Navbar -->
            <?php
            $debug = false;
            $searchKey = '';
            $gridTitle = '';
            $showSelection = false;
            $hideSearchArea = false;
            $response = [];
            $accommodations = [];

            if (!empty($_GET['key'])) {
                $searchKey = $_GET['key'];

                switch ($searchKey) {
                    case "CUS": //Future use.. isn't used currently
                        $gridTitle = "Devotee Search Result";
                        break;

                    case "PWD":
                        $gridTitle = "Incomplete Devotee Records with photo or ID";
                        break;

                    case "DWP":
                        $gridTitle = "Devotee Records without photo or ID";
                        break;

                    case "CTP":
                        $gridTitle = "Devotee Cards to be Printed";
                        $showSelection = TRUE;
                        break;

                    case "TMP":
                        $gridTitle = "Devotee Cards to be Printed";
                        $showSelection = TRUE;
                        break;

                    case "RPC":
                        $gridTitle = "Devotee Cards Recently Printed";
                        $showSelection = TRUE;
                        break;

                    case "AR":
                        $gridTitle = "Devotees Residing in Ashram (allocated accommodation)";
                        break;

                    default :
                        $gridTitle = "Devotee Search Result";
                        break;
                }

                $devoteeSearch = new clsDevoteeSearch($_GET);
                $response = $devoteeSearch->getDevoteeRecords($eventId);
                
                if($debug){echo "reaching after API call.."; var_dump( $_GET); var_dump($response); die;}
                unset($devoteeSearch);

                $hideSearchArea = TRUE;
            } else { //If custom search and criteria has not been specified, load accommodation options and available spots                
                $loadAccommodation = new clsOptionHandler("Accommodation");
                $loadAccommodation->setEventId($eventId);
                $accommodations = $loadAccommodation->getOptions();
                unset($loadAccommodation);
            }
            if($debug){echo "Probably empty values.."; var_dump( $_GET); var_dump($response); die;}
            ?>
                <!-- End Navbar -->
                <div class="content-search" <?php
                if ($hideSearchArea) {
                    print_r(" hidden=true");
                }
                ?> >

                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header card-header-primary">
                                <h4 class="card-title">Search Devotee</h4>
                            </div>
                            <div class="card-body">
                                <form  id="searchForm">                    
                                    <div class="row">
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label class="bmd-label-floating">First Name</label>
                                                <input type="text" class="form-control" name="devotee_first_name" id="devotee_first_name">
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label class="bmd-label-floating">Last Name</label>
                                                <input type="text" class="form-control" name="devotee_last_name" id="devotee_last_name" >
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label class="bmd-label-floating">Cell Phone Number</label>
                                                <input type="text" class="form-control" name="devotee_cell_phone_number" id="devotee_cell_phone_number" >
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group" >
                                                <label class="bmd-label-floating" >ID Number</label>
                                                <input type="text" class="form-control" name="devotee_id_number" id="devotee_id_number" >
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label class="bmd-label-floating">Accommodation</label>            
                                                <select type="text" class="form-control" name="devotee_accommodation_key" id="devotee_accommodation_key" >
                                                    <option value="">-Any Accommodation-</option>
                                                        <?php
                                                        if (!empty($accommodations)) {
                                                            foreach ($accommodations as $accommodation) {
                                                                print_r("<option value='" . $accommodation['accomodation_key'] . "'");
                                                                Print_r(">" . urldecode($accommodation['Accomodation_Name']) . " - " . $accommodation['Available_Count'] . "</option>");
                                                            }
                                                        }
                                                        ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group" >
                                                <label class="bmd-label-floating">Status</label>
                                                <select type="text" class="form-control" name="devotee_status" id="devotee_status" >
                                                    <option value="">-All Status-</option>
                                                    <option value="G">Good</option>
                                                    <option value="A">Average</option>
                                                    <option value="D">Day Visitor</option>
                                                    <option value="S">Senior Citizen</option>
                                                    <option value="B">Black Listed</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group" style="margin-top:62px">
                                                <label class="bmd-label-floating">Station</label>
                                                <input type="text" class="form-control" name="devotee_station" id="devotee_station" >
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group" style="margin-top:62px">
                                                <label class="bmd-label-floating">Remarks</label>
                                                <input type="text" class="form-control" name="devotee_remarks" id="devotee_remarks" >
                                            </div>
                                        </div>

                                    </div>                          
                                    <button type="reset" class="btn btn-success pull-right">Cancel</button>

                                    <button class="btn btn-success pull-right" onclick="submitSearch('searchForm', 1);
                                            return false;">Search</button>
                                </form>
                                <!--<div class="clearfix"></div>-->
                            </div>
                        </div>
                    </div>
                </div>
                <div class="content-search">
                    <div class="container-fluid">
                        <div class="card">
                            <div class="card-header card-header-primary">
                                <h4 class="card-title" id="pageHeader">
                                    <?php print_r($gridTitle); ?>
                                </h4>
                            </div>
                            <form id="printForm">
                                <div class="row">
                                    <div class="col-sm-12 col-md-12 col-lg-12 col-xl-12" >
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table">
                                                <thead class="text-primary">
                                                    <?php if ($showSelection) {
                                                        Print_r("
                                                                    <th>
                                                                        Select <input type='checkbox' name='headerCheck' id='headerCheck' value='' onclick=checkAll(); return false;>
                                                                    </th>");
                                                    }
                                                    ?>
                                                    <th>
                                                        Name
                                                    </th>
                                                    <th>
                                                        Devotee ID
                                                    </th>
                                                    <th>
                                                        Status
                                                    </th>
                                                    <th>
                                                        Station
                                                    </th>
                                                    <th>
                                                        Accommodation
                                                    </th>
                                                    <th>
                                                        Cell Number
                                                    </th>
                                                    <th>
                                                        Photo
                                                    </th>
                                                    <th>
                                                        ID Image
                                                    </th>
                                                </thead>
                                                <tbody>
<?php
$recordCount = 0;
if (!empty($response)) {



    foreach ($response as $devoteeRecord) {
        $devoteeKey = "--Unavailable--";
        $devoteeName = "--Unavailable--";
        $devoteeStation = "--Unavailable--";
        $devoteeStatus = "--Unavailable--";
        $devoteeCellNumber = "--Unavailable--";
        $devoteeAccommodation = "--Unavailable--";
        $devoteePhoto = "";
        $devoteeIdImage = "";
        $devoteeID = "";
        $devoteeStatusDetail = "Active";

        if (!empty($devoteeRecord['devotee_key'])) {
            $devoteeKey = urldecode($devoteeRecord['devotee_key']);
        }

        if (!empty($devoteeRecord['Devotee_Name'])) {
            $devoteeName = urldecode($devoteeRecord['Devotee_Name']);
        }

        if (!empty($devoteeRecord['devotee_station'])) {
            $devoteeStation = urldecode($devoteeRecord['devotee_station']);
        }

        if (!empty($devoteeRecord['accomodation_name'])) {
            $devoteeAccommodation = urldecode($devoteeRecord['accomodation_name']);
        }

        if (!empty($devoteeRecord['devotee_status'])) {
            
            $devoteeStatus = urldecode($devoteeRecord['devotee_status']);
            switch ($devoteeStatus) {
                case "G":
                    $devoteeStatusDetail = "Good";
                    break;

                case "A":
                    $devoteeStatusDetail = "Average";
                    break;

                case "D":
                    $devoteeStatusDetail = "Day Visitor";
                    break;

                case "S":
                    $devoteeStatusDetail = "Senior Citizen";
                    break;

                case "B":
                    $devoteeStatusDetail = "Black Listed";
                    break;
            }
        }

        if (!empty($devoteeRecord['devotee_cell_phone_number'])) {
            $devoteeCellNumber = urldecode($devoteeRecord['devotee_cell_phone_number']);
        }

        $photoProxyBase = htmlspecialchars(rtrim((string) $config_data['webroot'], '/') . '/Logic/devoteePhotoProxy.php', ENT_QUOTES, 'UTF-8');
        $escapedKey = htmlspecialchars($devoteeKey, ENT_QUOTES, 'UTF-8');
        $hasPhoto = !empty($devoteeRecord['has_photo']) || !empty($devoteeRecord['Devotee_Photo']);
        $hasIdImage = !empty($devoteeRecord['has_id_image']) || !empty($devoteeRecord['Devotee_ID_Image']);

        if ($devoteeKey != "--Unavailable--") {
            $recordCount = $recordCount + 1;

            print_r("<tr>");
            if ($showSelection) {
                print_r("<td> <input type='checkbox' name='" . $recordCount . "' id='" . $recordCount . "' value='" . $devoteeKey . "'> </td>");
            }
            print_r(" <td>
                            <a href='addDevoteeI.php?devotee_key=" . $devoteeKey . "'>" . $devoteeName . "</a>
                        </td>
                        <td>
                            <a href='addDevoteeI.php?devotee_key=" . $devoteeKey . "'>" . $devoteeKey . "</a>
                        </td>
                        <td>
                            <a href='addDevoteeI.php?devotee_key=" . $devoteeKey . "'>" . $devoteeStatusDetail . "</a>
                        </td>
                        <td>
                            <a href='addDevoteeI.php?devotee_key=" . $devoteeKey . "'>" . $devoteeStation . "</a>
                        </td>
                        <td>
                            <a href='addDevoteeI.php?devotee_key=" . $devoteeKey . "'>" . $devoteeAccommodation . "</a>
                        </td>
                        <td>
                            <a href='addDevoteeI.php?devotee_key=" . $devoteeKey . "'>" . $devoteeCellNumber . "</a>
                        </td><td>");
            if (!$hasPhoto) {
                print_r('<img src="../assets/img/faces/devotee.ico" alt="Devotee Image" height="70" width="70"></img>');
            } else {
                print_r('<img src="' . $photoProxyBase . '?devotee_key=' . $escapedKey . '&type=photo" loading="lazy" width="70" height="70" alt="devotee image" onerror="this.src=\'../assets/img/faces/devotee.ico\'"></img>');
            }

            print_r("</td> <td>");

            if (!$hasIdImage) {
                print_r('<img src="../assets/img/faces/doc.png" alt="Devotee ID Image" height="65" width="65"></img>');
            } else {
                print_r('<img src="' . $photoProxyBase . '?devotee_key=' . $escapedKey . '&type=id" loading="lazy" width="70" height="70" alt="devotee ID image" onerror="this.src=\'../assets/img/faces/doc.png\'"></img>');
            }

            print_r("</td> </td> </tr>");
        }
    }
}
?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                                <div class="col-sm-12 col-md-12 col-lg-12 col-xl-12" >
                                    <div class="card-body">

                                        <button type="submit" <?php if (!$showSelection) {
                                                    print_r("hidden='true'");
                                                } ?> class="btn btn-success pull-right" >Cancel</button>
                                        <button type="submit" hidden='true' class="btn btn-success pull-right">Add Devotee without photo/image</button>
                                        
                                        <button type="submit" <?php if (!$showSelection) {
                                                    print_r("hidden='true'");
                                                } ?> class="btn btn-success pull-right" onclick="removePrint('printForm', 1); return false;">Cancel Print for Selected Cards</button>
                                        <button type="submit" <?php if (!$showSelection) {
                                                    print_r("hidden='true'");
                                                } ?> class="btn btn-success pull-right" onclick="submitPrint('printForm', 1); return false;">Print Selected Cards</button>
                                        <div class="clearfix"></div>

                                    </div>
                                </div>
                            </div>
                            </form>
                        </div>
                    </div>
                </div>                     
            </div>

            <!-- id modial -->
        </div>
        <!--   Core JS Files   -->
        <script>
            document.getElementById('pageHeader').textContent = document.getElementById('pageHeader').textContent + "(" + <?php print_r($recordCount); ?> + " records found!)";
        </script>
<?php include_once("scriptJS.php") ?>
    </body>

</html>
