<?php
/**
 * Loaded from index.php inside <div class="main-panel"> — do not output HTML until server-side CURL
 * to getReport/loadOptions completes (kdms_* session handshake).
 */
declare(strict_types=1);

$debug = false;

include_once __DIR__ . '/../Logic/clsDevoteeSearch.php';
include_once __DIR__ . '/../Logic/clsReportHandler.php';
include_once __DIR__ . '/../Logic/clsOptionHandler.php';
$config_data = include __DIR__ . '/../site_config.php';

$eventId = $config_data['event_id'];

$getReport = new clsReportHandler();
$response = $getReport->getAccommodationCounts($eventId);
unset($getReport);

if ($debug) {
    echo 'eventId =: ', htmlspecialchars((string) $config_data['event_id'], ENT_QUOTES, 'UTF-8'),
    htmlspecialchars(var_export($response, true), ENT_QUOTES, 'UTF-8');
}

$dashboardStat = static function (?array $rows, int $idx, string $key): string {
    if ($rows === null || ! isset($rows[$idx]) || ! is_array($rows[$idx]) || ! array_key_exists($key, $rows[$idx])) {
        return '';
    }

    return htmlspecialchars((string) $rows[$idx][$key], ENT_QUOTES, 'UTF-8');
};

$accoType = 'All';

if (! empty($_GET['accoType'])) {
    $accoType = $_GET['accoType'];
}
$sevaType = 'All';

if (! empty($_GET['sevaType'])) {
    $sevaType = $_GET['sevaType'];
}

$getReport = new clsReportHandler();
$AccoResponse = $getReport->getAccommodationRecords($accoType, $eventId);
unset($getReport);
if ($debug) {
    echo 'Accociation response =: ', htmlspecialchars(var_export($AccoResponse, true), ENT_QUOTES, 'UTF-8');
}
if (! is_array($AccoResponse)) {
    $AccoResponse = [];
}

$sevaSearch = new clsOptionHandler('Seva'); // Constructor =>request=>optionType=>Seva
$sevaSearch->setEventId($eventId);
$sevaSearch->setOptionKey($sevaType); // Option Key for the API => either all or specific seva count (like assigned)
$sevaRes = $sevaSearch->getOptions();
unset($sevaSearch);

if (! is_array($sevaRes)) {
    $sevaRes = [];
}
?> 
<script>
    //Debug only function.. do not use
    function clickHandler2(formId, flag) {

        //document.getElementById("requestType").value = "refreshAcco";
        var formData = $(formId).serialize();
        alert(formData);
        alert(formId);
        document.getElementById(formId).action = "<?=$config_data['webroot'];?>Logic/requestManager.php";
        document.getElementById(formId).method = "POST";
        //document.getElementById("myFormID").data = formData;
        document.getElementById(formId).submit();

    }

    //javascript function for ajax call
    function clickHandler(formId, flag) {
       var formData = $(formId).serialize();

        <?php
        if($debug){
            echo "alert(formData);";
        }
        ?>
        if (validateInput()) {

            switch (flag) {
                case 1: //Refresh count

                    $.ajax({
                        url: "<?=$config_data['webroot'];?>Logic/requestManager.php",
                        type: 'POST',
                        data: formData,
                        success: function (response) {
                            var r = JSON.parse(response);

                            if (r['status'] == true) {
                                alert("Accomodation count refreshed successfully!");
                            } else {
                                alert(response);
                            }
                        }
                    });
                    break;

                case 2: //Refresh count
                    document.getElementById("requestType").value = "refreshSeva";
                    formData = $(formId).serialize();

                    $.ajax({
                        url: "<?=$config_data['webroot'];?>Logic/requestManager.php",
                        type: 'POST',
                        data: formData,
                        success: function (response) {
                            var r = JSON.parse(response);
                            if (r['status'] == true) {
                                alert("Seva count refreshed successfully!");
                            } else {
                                alert(response);
                            }
                        }
                    });
                    break;


//                case 2: //Manage accommodations
//                    document.getElementById("myForm").action = "addAccommodationII.php";
//                    document.getElementById(formId).submit();
//                    break;
//
                default:
                    break;
            }
        }
    }
    function validateInput() {
        return true;
    }
    
    function generateReport(formId, flag) {
                printForm = document.getElementById(formId);
//                var I = 0;
                var printString = ""
//                for (I = 0; I < printForm.length; I++) {
//                    if (printForm[I].value != "") {
//                        //alert(searchForm[I].id + ": " + searchForm[I].value);
//                        if (printForm[I].type == 'checkbox' && printForm[I].checked) {
//                            printString = printString + "'" + encodeURI(printForm[I].value) + "',";
//                        }
//                    }
//                }

//                if (printString.length > 1) {

                    window.open("./rptDutyReport.php?mode=CUS&key=devotee_accommodation_key=MP");
                    //window.open("./rptCardsPrint.php?key=" + printString.substr(0, printString.length - 1) + "&mode=PCD");
                    //window.location.assign("./devoteeSearchResult.php?mode=SET&key=CTP");

                    //if(confirm("Card printed successfully?")){
//                    $.ajax({
//                        url: '<?=$config_data['webroot']?>Logic/requestManager.php',
//                        type: 'POST',
//                        data: {'devotee_key': printString.substr(0, printString.length - 1), 'requestType': "removeFromPrintQueue"},
//                        async: false,
//                        success: function (response) {
//
//                            var r = JSON.parse(response);
//
//                            if (r['flag'] == true) {
//                                alert("Card removed from the printing queue!");
//                                window.location.assign("./devoteeSearchResult.php?mode=SET&key=CTP");
//                            } else {
//                                alert(r['message']);
//                                updateSuccess = false;
//                            }
//                        }
//                    });
                    //            }

//                } else {
//                    alert("Please select a card to print!");
//                }
            }
</script>
<div class="content">
    <div class="container-fluid">
        <div class="row">
            <div class="col-lg-4 col-md-6 col-sm-6">
                <div class="card card-stats">
                    <div class="card-header card-header-warning card-header-icon">
                        <div class="card-icon">
                            <i class="material-icons">add</i>
                        </div>
                        <p class="card-category">Registration</p>
                        <h3 class="card-title"> Registration</h3>
                    </div>
                    <div class="card-footer">
                        <div class="stats">
                            <i class="material-icons text-danger">image</i>
                            <a href="../UI/addDevoteeI.php" class="dash-link">Photo and ID Scan</a>
                        </div>
                    </div>
                    <div class="card-footer">
                        <div class="stats">
                            <i class="material-icons text-danger">add</i>
                            <a href="../UI/addDevoteeI.php"  class="dash-link">Add Devotee</a>
                        </div>
                    </div>
                    <div class="card-footer">
                        <div class="stats">
                            <i class="material-icons text-danger">print</i>
                            <a href="../UI/devoteeSearchResult.php?mode=SET&key=CTP" class="dash-link">Print Cards</a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4 col-md-6 col-sm-6">
                <div class="card card-stats">
                    <div class="card-header card-header-success card-header-icon">
                        <div class="card-icon">
                            <i class="material-icons">edit</i>
                        </div>
                        <p class="card-category">Update</p>
                        <h3 class="card-title">Devotee Update</h3>
                    </div>
                    <div class="card-footer">
                        <div class="stats">
                            <i class="material-icons text-danger">search</i>
                            <a href="./devoteeSearchResult.php?mode=CUS&key=" class="dash-link">Search Devotee</a>
                        </div>
                    </div>
                    <div class="card-footer">
                        <div class="stats">
                            <i class="material-icons text-danger">edit</i>
                            <a href="../UI/devoteeSearchResult.php?mode=CUS&key=" class="dash-link">Modify Devotee Record</a>
                        </div>
                    </div>
                    <div class="card-footer">
                        <div class="stats">
                            <i class="material-icons text-danger">print</i>
                            <a href="./devoteeSearchResult.php?mode=SET&key=PWD" class="dash-link">Add Devotee Info to Photos/ID</a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4 col-md-6 col-sm-6">
                <form name="myForm1" id="myFormID1">
                    <input type="hidden" name="requestType1" id="requestType1" value="none">
                    <div class="card card-stats">
                        <div class="card-header card-header-danger card-header-icon">
                            <div class="card-icon">
                                <i class="material-icons">links</i>
                            </div>
                            <p class="card-category">Links</p>
                            <h3 class="card-title">Quick Links</h3>
                        </div>
                        <div class="card-footer">
                            <div class="stats">
                                <i class="material-icons text-danger">refresh</i>
                                <a href="addSevaII.php" class="dash-link">Manage Seva Types</a>
                            </div>
                        </div>
                        <div class="card-footer" >
                            <div class="stats">
                                <i class="material-icons text-danger">add</i>
                                <a href="addAccommodationII.php" class="dash-link">Manage Accommodations</a>
                            </div>
                        </div>
                        <div class="card-footer">
                            <div class="stats">
                                <i class="material-icons text-danger">edit</i>
                                <a href="upsertAmenityII.php" class="dash-link">Manage Amenities</a>
                            </div>
                        </div>
                    </div>             
                </form>
            </div>
        </div>
       <div class="row">
            <div class="col-lg-4 col-md-6 col-sm-6">
                <div class="card card-stats">
                    <div class="card-header card-header-warning card-header-icon">
                        <div class="card-icon">
                            <i class="material-icons">hotel</i>
                        </div>
                        <p class="card-category">Statistics</p>
                        <h3 class="card-title"> Accommodations</h3>
                    </div>
                    <div class="card-footer">
                        <div class="stats">
                            <i class="material-icons text-danger">home</i>
                            <a href="../UI/index.php?accoType=Occupied" class="dash-link">Total Spaces Allocated:  
                                <b>  <?= $dashboardStat(is_array($response) ? $response : null, 0, 'SpaceOccupiedOrDevoteesPresent'); ?> </b> </a>
                        </div>
                    </div>
                    <div class="card-footer">
                        <div class="stats">
                            <i class="material-icons text-danger">home</i>
                            <a href="../UI/index.php?accoType=Available" class="dash-link">Total Spaces Available:  
                                <b>  <?= $dashboardStat(is_array($response) ? $response : null, 2, 'AvailableSpaces'); ?> </b> </a>
                        </div> 
                    </div>
                    <div class="card-footer">
                        <div class="stats">
                            <i class="material-icons text-danger">home</i>
                            <a href="../UI/index.php?accoType=Reserved" class="dash-link">Total Spaces Reserved:
                                <b>  <?= $dashboardStat(is_array($response) ? $response : null, 3, 'ReservedSpaces'); ?> </b> </a></div> 
                    </div>
                </div>
            </div>
            <div class="col-lg-4 col-md-6 col-sm-6">
                <div class="card card-stats">
                    <div class="card-header card-header-success card-header-icon">
                        <div class="card-icon">
                            <i class="material-icons">people</i>
                        </div>
                        <p class="card-category">Statistics</p>
                        <h3 class="card-title">Devotees</h3>
                    </div>
                    <div class="card-footer">
                        <div class="stats">
                            <i class="material-icons text-danger">home</i>
                            <a href="../UI/devoteeSearchResult.php?mode=SET&amp;key=AR" class="dash-link">Devotees Residing in Ashram:  
                                <b>  <?= $dashboardStat(is_array($response) ? $response : null, 0, 'SpaceOccupiedOrDevoteesPresent'); ?> </b> </a>
                        </div>
                    </div>
                    <div class="card-footer">
                        <div class="stats">
                            <i class="material-icons text-danger">home</i>
                            <a href="../UI/devoteeSearchResult.php?mode=CUS&amp;key=devotee_accommodation_key=othr" class="dash-link">Total Day Visitors:
                                <b>  <?= $dashboardStat(is_array($response) ? $response : null, 8, 'TotalDayVisitors'); ?> </b> </a></div>
                    </div>
                    <div class="card-footer">
                        <div class="stats">
                            <i class="material-icons text-danger">home</i>
                            <a href="../UI/devoteeSearchResult.php?mode=CUS&amp;key=devotee_accommodation_key=LCL|OWNAR" class="dash-link">Devotees with Own Arrangement:
                                <b>  <?= $dashboardStat(is_array($response) ? $response : null, 4, 'DevoteesWithOwnArrangements'); ?> </b> </a></div>
                    </div>
                    <div class="card-footer">
                        <div class="stats">
                            <i class="material-icons text-danger">home</i>
                            <a href="../UI/index.php?sevaType=Assigned" class="dash-link">Devotees Registered for Seva:
                                <b>  <?= $dashboardStat(is_array($response) ? $response : null, 1, 'DevoteesRegisteredForSeva'); ?> </b> </a>
                        </div> 
                    </div>
                </div>
            </div>
            <div class="col-lg-4 col-md-6 col-sm-6">
                <form name="myForm" id="myFormID">
                    <input type="hidden" name="requestType" id="requestType" value="refreshAcco">
                    <input type="hidden" name="eventId" id="eventId" value="<?php echo $config_data['event_id']; ?>">
                    <div class="card card-stats">
                        <div class="card-header card-header-danger card-header-icon">
                            <div class="card-icon">
                                <i class="material-icons">settings</i>
                            </div>
                            <p class="card-category">Maintenance</p>
                            <h3 class="card-title">Admin Tasks</h3>
                        </div>
                        <div class="card-footer" onclick="clickHandler('#myFormID', 1); return false;">
                            <div class="stats">
                                <i class="material-icons text-danger">refresh</i>
                                <a href class="dash-link">Refresh Accommodation Counts</a>
                            </div>
                        </div>
                        <div class="card-footer" onclick="clickHandler('#myFormID', 2); return false;">
                            <div class="stats">
                                <i class="material-icons text-danger">refresh</i>
                                <a href class="dash-link">Refresh Seva Counts</a>
                            </div>
                        </div>
                        <div class="card-footer" onclick="generateReport('#myFormID', 2); return false;">
                            <div class="stats">
                                <i class="material-icons text-danger">home</i>
                                <a href class="dash-link">Generate Mal Pua Report</a>
                            </div>
                        </div>
                    </div>             
                </form>
            </div>
        </div> 
        <div class="row">
                <div class="col-xl-8 col-lg-12 col-md-12 col-sm-12">
                    <div class="card">
                        <div class="card-header card-header-primary">
                            <h4 class="card-title">
                                <?php
                                print_r($accoType . " ");
                                ?>
                                Accommodations </h4>
                        </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class=" text-primary">
                                        <th align='left'>
                                            Accommodation Name
                                        </th>
                                        <th align='left'>
                                            Capacity
                                        </th>
                                        
                                        <th align='left'>
                                            Allocated 
                                        </th>
                                        
                                        <th align='left'>
                                            Reserved
                                        </th>
                                        <th align='left'>
                                            Unavailable 
                                        </th>
                                        <th align='left'>
                                            Available 
                                        </th>
                                        </thead>
                                        <tbody >
                                        <tr>
                                            <td colspan="12">
                                            <div class="scrollbar-dash" id="style-6">
                                                <table class="table table-striped"> 
                                            <?php
                                            $recordCount = 0;
                                            if (!empty($AccoResponse)) {
                                                foreach ($AccoResponse as $accommodationRecord) {
                                                    if (! is_array($accommodationRecord)) {
                                                        continue;
                                                    }
                                                    $accomodationKey = "--Unavailable--";
                                                    $accomodationName = "--Unavailable--";
                                                    $accomodationCapacity = "--";
                                                    $reservedCount = "--";
                                                    $outOfAvailabilityCount = "--";
                                                    $allocatedCount = "--";
                                                    $availableCount = "--";
                                                    $occupiedCount = "--";

                                                    $k = $accommodationRecord['accomodation_key']
                                                        ?? $accommodationRecord['Accomodation_Key'] ?? null;
                                                    $nm = $accommodationRecord['accomodation_name']
                                                        ?? $accommodationRecord['Accomodation_Name'] ?? null;
                                                    $cap = $accommodationRecord['accomodation_capacity']
                                                        ?? $accommodationRecord['Accomodation_Capacity'] ?? null;
                                                    $allo = $accommodationRecord['allocated_count']
                                                        ?? $accommodationRecord['Allocated_Count'] ?? null;
                                                    $resa = $accommodationRecord['reserved_count']
                                                        ?? $accommodationRecord['Reserved_Count'] ?? null;
                                                    $ooo = $accommodationRecord['Out_of_Availability_Count']
                                                        ?? $accommodationRecord['out_of_availability_count'] ?? null;
                                                    $avail = $accommodationRecord['available_count']
                                                        ?? $accommodationRecord['Available_Count'] ?? null;

                                                    if (!empty($k)) {
                                                        $accomodationKey = urldecode((string) $k);
                                                    }

                                                    if (!empty($nm)) {
                                                        $accomodationName = urldecode((string) $nm);
                                                    }

                                                    if (!empty($cap) || $cap === '0' || $cap === 0) {
                                                        $accomodationCapacity = is_scalar($cap) ? (string) $cap : urldecode((string) $cap);
                                                    }

                                                    if (!empty($allo) || $allo === '0' || $allo === 0) {
                                                        $allocatedCount = $allo;
                                                    }

                                                    if (!empty($resa) || $resa === '0' || $resa === 0) {
                                                        $reservedCount = urldecode((string) $resa);
                                                    }

                                                    if (!empty($ooo) || $ooo === '0' || $ooo === 0) {
                                                        $outOfAvailabilityCount = $ooo;
                                                    }

                                                    if (!empty($avail) || $avail === '0' || $avail === 0) {
                                                        $availableCount = $avail;
                                                    }
                                                    
                                                    if ($accomodationKey != "--Unavailable--") {
                                                        $recordCount = $recordCount + 1;

                                                        print_r("
                                                            <tr >
                                                            <td align='left'>
                                                                <a href='addAccommodationI.php?accommodation_key=" . $accomodationKey . "'>" . $accomodationName . "</a>
                                                            </td>
                                                            <td align='left' class='table-data'>
                                                                <a href='./devoteeSearchResult.php?mode=AOD&key=" . $accomodationKey . "&eventId=" . $eventId . "'>"  . $accomodationCapacity . "</a>
                                                            </td>
                                                            <td align='left' class='table-data'>
                                                                <a href='./devoteeSearchResult.php?mode=AOD&key=" . $accomodationKey . "&eventId=" . $eventId . "'>" . $allocatedCount . "</a>
                                                            </td>
                                                            
                                                            <td align='left' class='table-data'>
                                                                <a href='addAccommodationI.php?accommodation_key=" . $accomodationKey . "'>" . $reservedCount . "</a>
                                                            </td>
                                                            
                                                            <td align='left' class='table-data'>
                                                                <a href='addAccommodationI.php?accommodation_key=" . $accomodationKey . "'>" . $outOfAvailabilityCount . "</a>
                                                            </td>
                                                            <td align='left' class='table-data'>
                                                                <a href='addAccommodationI.php?accommodation_key=" . $accomodationKey . "'>" . $availableCount . "</a>
                                                            </td>
                                                            </tr>
                                                            ");
                                                    }
                                                }
                                            }
                                            ?>
                                            </table>
                                        </div>
                                        </td>
                                        </tr>
                                        </tbody>
                                    </table>
                                </div>                  
                            </div>
                    </div>
                </div>
                <div class="col-xl-4 col-lg-12 col-md-12 col-sm-12">
                    <div class="card">
                        <div class="card-header card-header-primary">
                            <!-- <h4 class="card-title">Seva Assignment Counts </h4> -->
                            <h4 class="card-title">
                                <?php
                                    if($sevaType==""){
                                        echo "All";
                                    }
                                    else {
                                        echo $sevaType;
                                    }
                                    // print_r($accoType . " ");
                                ?>
                            Sevas
                            </h4>
                        </div>
                            <div class="card-body">
                            <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class=" text-primary">
                                <th align='left'>
                                    Seva
                                </th>
                                <th align='left' class="assign-devotees">
                                    Assigned Devotees 
                                </th>
                                </thead>
                                <tbody >
                                <tr>
                                    <td colspan="12">
                                    <div class="scrollbar-dash" id="style-6">
                                        <table class="table table-striped"> 
                                    <?php
                                    $sevaRecordCount = 0;
                                    if (!empty($sevaRes)) {
                                        foreach ($sevaRes as $sevaRecord) {
                                            if (! is_array($sevaRecord)) {
                                                continue;
                                            }
                                            $sevaID = "--Unavailable--";
                                            $sevaDesc = "--Unavailable--";
                                            $assignCount = "--";

                                            $sid = $sevaRecord['Seva_Id'] ?? $sevaRecord['seva_id'] ?? null;
                                            $sd = $sevaRecord['Seva_Description'] ?? $sevaRecord['seva_description'] ?? null;
                                            $ac = $sevaRecord['assigned_count'] ?? null;

                                            if ($sid !== null && $sid !== '') {
                                                $sevaID = urldecode((string) $sid);
                                            }

                                            if ($sd !== null && $sd !== '') {
                                                $sevaDesc = urldecode((string) $sd);
                                            }

                                            if ($ac !== null && $ac !== '') {
                                                $assignCount = $ac;
                                            }
                                            if ($sevaDesc != "--Unavailable--") {
                                                $sevaRecordCount = $sevaRecordCount + 1;                         
                                                print_r("
                                                    <tr >
                                                    <td align='left'>
                                                        <a href='./devoteeSearchResult.php?mode=ADS&key=" . $sevaID . "&eventId=" . $eventId . "'>" . $sevaDesc . "</a>
                                                    </td>
                                                    <td align='left' class='table-data'>
                                                        <a href='./devoteeSearchResult.php?mode=ADS&key=" . $sevaID . "&eventId=" . $eventId . "'>" . $assignCount . "</a>
                                                    </td>
                                                    
                                                    </tr>
                                                ");
                                            }
                                        }
                                    }
                                    ?>
                                    </table>
                                </div>
                                </td>
                                </tr>
                                </tbody>
                            </table>
                            </div>                  
                            </div>
                    </div>
                </div>
        </div>
    </div>
    </div>
    </div>
</div>
