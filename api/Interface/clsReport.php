<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of clsOptions
 *
 * @author agupta
 */
class clsReport {

    private $conn;
    private $debug = false;
// constructor with $db as database connection
    public function __construct($db) {
        $this->conn = $db;
    }

    public function getReport($requestData) {
        $res = array();
        $res['status'] = false;
        $res['message'] = '';

        if (!empty($requestData['accoType'])) {
            $AccoSpecific = $requestData['accoType'];
        } else {
            $AccoSpecific = "All";
        }

        if (!empty($requestData['eventId'])) {
            $eventId = $requestData['eventId'];
        } else {
            $eventId = "";
        }

        if (!empty($requestData['key'])) {
            $key = $requestData['key'];
        } else {
            $key = "";
        }

        $photoRequired = "N";
        if (!empty($requestData['photo_required'])) {
            $photoRequired = strtoupper((string) $requestData['photo_required']);
        }

        $status = true;
        if (!empty($requestData['type'])) {
            switch ($requestData['type']) {
                case "AccoCount": //Accommodation Counts
                    return $this->getAccommodationCounts($AccoSpecific, $eventId);
                    break;
                
                case "AccoAvailability":
                    return $this->getAccommodationAvailability($eventId);
                    break;

                case "DevoteeCount": //Accommodation Counts
                    return $this->getDevoteeCounts($eventId);
                    break;
                
                case "DutyReport": //Accommodation Counts

                    return $this->getDutyReport($key, $eventId, $photoRequired);
                    
                    break;

                default :
                    $res['message'] = "Request type invalid!";
                    return $res;
                    break;
            }
        } else {
            $res['message'] = "Request type not specified!";
            return $res;
        }
    }

    //Returns accommodations and their counts
    private function getAccommodationCounts($AccoSpecific, $eventId) {
        $query = "SELECT" .
                " aa.accomodation_key, " .
                " am.accomodation_name, " .
                " aa.available_count," .
                " ( " .
                " am.accomodation_capacity - aa.available_count" .
                " ) AS occupied_count," .
                " aa.reserved_count," .
                " am.accomodation_capacity, " .
                " aa.allocated_count," .
                " aa.Out_of_Availability_Count" .
                " FROM" .
                " accommodation_availability aa" .
                " LEFT OUTER JOIN accommodation_master am ON" .
                " aa.accomodation_key = am.Accomodation_Key" .
                " WHERE";

        switch ($AccoSpecific) {
            case "Available":
                $query = $query . " aa.Available_Count > 0 ";
                break;

            case "Reserved":
                $query = $query . " aa.Reserved_Count > 0 ";
                break;

            case "Occupied":
                $query = $query . " aa.Allocated_Count > 0 ";
                break;

            case "Allocated":
                $query = $query . " aa.Allocated_Count > 0 ";
                break;

            case "All":
            default:
                $query = $query . " 1=1 ";
                break;
        }

        if($eventId != ""){
            $query = $query . " AND  aa.Accommodation_Event = '" . $eventId . "' ";
        }

        //$query = $query . " Order by Available_Count DESC";

        $query = $query . " Order by am.accomodation_name ";

        if($this->debug){echo $query; }

        $results = $this->conn->query($query);

        $accommodationResult = array();
        if ($results === false) {
            return $accommodationResult;
        }
        $i = 0;
        while ($row = $results->fetchObject()) {
            $accommodationResult[] = $row;
            $i = $i + 1;
        }
        return $accommodationResult;
    }

    private function getAccommodationAvailability($eventId) {
        $res = array();
        $res['status'] = false;
        $res['message'] = '';

        if (empty($eventId)) {
            $res['message'] = "Event ID is missing.";
            return $res;
        }

        $query = "SELECT
                    aa.Accomodation_Key AS accomodation_key,
                    IFNULL(am.Accomodation_Name, aa.Accomodation_Key) AS accomodation_name,
                    IFNULL(am.Accomodation_Capacity, 0) AS accomodation_capacity,
                    aa.Accommodation_Event AS event_id,
                    aa.Allocated_Count AS allocated_count,
                    aa.Available_Count AS available_count
                  FROM accommodation_availability aa
                  LEFT OUTER JOIN accommodation_master am
                    ON aa.Accomodation_Key = am.Accomodation_Key
                  WHERE aa.Accommodation_Event = '" . $eventId . "'
                  ORDER BY am.Accomodation_Name";

        if($this->debug){echo $query; }

        $results = $this->conn->query($query);
        $availabilityResult = array();
        if ($results === false) {
            return $availabilityResult;
        }

        while ($row = $results->fetchObject()) {
            $availabilityResult[] = $row;
        }

        return $availabilityResult;
    }

    //Returns 
    //1. Total devotees present in the ashram
    //2. Total devotees registered this year
    //3. Total spaces available for allocation
    //4. Total spaces reserved

    private function getDevoteeCounts($eventId) {
        $query = array();
        $devoteeResults = array();


        //1. Devotees with allocated accommodation for the event (dashboard drill-down: SET key AR)
        $query[0] = "SELECT COUNT(DISTINCT acco.Devotee_Key) AS SpaceOccupiedOrDevoteesPresent
            FROM devotee_accomodation acco
            WHERE acco.Accomodation_Status = 'Allocated'";

        //2. Devotees registered for seva (assigned), excluding seva code UN
        $query[1] = "SELECT COUNT(DISTINCT ds.Devotee_Key) AS DevoteesRegisteredForSeva
            FROM devotee_seva ds
            WHERE ds.Seva_Status = 'Assigned'
            AND ds.Seva_ID <> 'UN'";
        //3. Total spaces available for allocation
        $query[2] = "SELECT sum(acco.Available_Count) as AvailableSpaces FROM `accommodation_availability` acco where acco.Available_Count < 1000";

        //4. Total spaces reserved
        $query[3] = "SELECT sum(acco.Reserved_Count) as ReservedSpaces FROM `accommodation_availability` acco where acco.Available_Count < 1000";

        //5. Devotees with own arrangement / local (accommodation keys LCL, OWNAR)
        $query[4] = "SELECT COUNT(DISTINCT acco.Devotee_Key) AS DevoteesWithOwnArrangements
            FROM devotee_accomodation acco
            WHERE acco.Accomodation_Status = 'Allocated'
            AND acco.Accomodation_Key IN ('LCL', 'OWNAR')";

        //9. Day visitors (accommodation key othr)
        $query[8] = "SELECT COUNT(DISTINCT acco.Devotee_Key) AS TotalDayVisitors
            FROM devotee_accomodation acco
            WHERE acco.Accomodation_Status = 'Allocated'
            AND LOWER(acco.Accomodation_Key) = 'othr'";

        //6. Total mature devotees (12 years or older))
        $query[5] = "SELECT count(distinct dm.devotee_key) as MatureDevotee FROM devotee_accomodation acco
                        LEFT OUTER JOIN devotee dm ON acco.devotee_key = dm.devotee_key AND (DATE_FORMAT(FROM_DAYS(DATEDIFF(NOW(), dm.Devotee_DOB)), '%Y') + 0) < 60 
                    WHERE  acco.accomodation_status = 'Allocated' ";

        //7. Total Senior devotees (60 Years or Older)
        $query[6] = "SELECT count(distinct dm.devotee_key) as SeniorDevotee FROM devotee_accomodation acco
                        LEFT OUTER JOIN devotee dm ON acco.devotee_key = dm.devotee_key AND (DATE_FORMAT(FROM_DAYS(DATEDIFF(NOW(), dm.Devotee_DOB)), '%Y') + 0) >= 60 
                    WHERE  acco.accomodation_status = 'Allocated' AND dm.devotee_key IS NOT NULL ";

        //8. Total Male and female devotees
        $query[7] = "SELECT count(distinct dm.devotee_key) as MaleDevotee, count(df.devotee_key) as FemaleDevotee, count(du.devotee_key) as UnknownGender FROM devotee_accomodation acco
                        LEFT OUTER JOIN devotee dm ON acco.devotee_key = dm.devotee_key AND dm.devotee_gender = 'M'
                        LEFT OUTER JOIN devotee df ON acco.devotee_key = df.devotee_key AND df.devotee_gender = 'F'
                        LEFT OUTER JOIN devotee du ON acco.devotee_key = du.devotee_key AND (du.devotee_gender = '' OR du.devotee_gender is null)
                     WHERE acco.accomodation_status = 'Allocated' ";
        
        


        for ($i = 0; $i < sizeof($query); $i++) {
            if ($eventId !== '') {
                if ($i === 1) {
                    $query[$i] .= " AND ds.Seva_Event = '" . $eventId . "'";
                } else {
                    $query[$i] .= " AND acco.Accommodation_Event = '" . $eventId . "'";
                }
                if ($this->debug) {
                    echo '<br>', $query[$i], '<br>';
                }
            }

            $result = $this->conn->query($query[$i]);
            if (!empty($row = $result->fetchObject())) {
                $devoteeResults[$i] = $row;
                if($this->debug){var_dump($row);}
            }
        }

        return $devoteeResults;
    }

    private function getDutyReport($key="",$eventId, $photoRequired = "N") {
        
        $res = array();
        $res['status'] = false;
        $res['message'] = '';
        $errormsg = "";
        $status = true;
        
        if (empty($eventId)) {
            $errormsg .= "Event ID is missing.";
            $status = false;
        }       
        
        if ($status == false) {
            $res['status'] = $status;
            $res['message'] = $errormsg;
            return $res;
            die;
        }

        if($this->debug){echo "key passed is: ", $key; }
        //concat('(',substr(num_cleansed,1,3),') ',substr(num_cleansed,4,3),'-',substr(num_cleansed,7)) AS num_formatted
        // Phase 6 Stream B: never hydrate BLOBs for duty report; kmreports lazy-loads via devoteePhotoProxy.
        $photoSelect = "'' AS devotee_photo";
        $photoJoin = "";

        $baseQuery = "SELECT dlm.duty_location_name, d.devotee_key, CONCAT(d.devotee_first_name , ' ' , d.devotee_last_name) AS devotee_name, " . $photoSelect . ",
                        IFNULL(CONCAT('(', SUBSTR(d.devotee_cell_phone_number, 1, 3),')-', SUBSTR(d.devotee_cell_phone_number, 4, 3), '-', SUBSTR(d.devotee_cell_phone_number, 7)),  '(###)-###-####') AS devotee_cell_phone_number
                  FROM duty_location_master dlm 
                    LEFT OUTER JOIN office_duty od ON dlm.duty_location_key = od.Duty_Location_Key
                    LEFT OUTER JOIN devotee d ON od.devotee_key = d.devotee_key
                    " . $photoJoin . "
                  WHERE d.devotee_key IS NOT NULL";

        $sanitizedKey = "";
        if($key != ""){
            $sanitizedKey = trim(urldecode($key));
            if(substr($sanitizedKey, 0) == "," or substr($sanitizedKey, -1) == ",") {
                $sanitizedKey = trim($sanitizedKey, ",");
            }
            $sanitizedKey = str_replace(",", "','", $sanitizedKey);
        }

        $buildQuery = function ($applyEventFilter) use ($baseQuery, $eventId, $sanitizedKey) {
            $query = $baseQuery;
            if ($applyEventFilter) {
                $query = $query . " AND od.duty_event =  '" . $eventId . "'";
            }
            if ($sanitizedKey !== "") {
                $query = $query . " AND dlm.duty_location_key IN ('" . $sanitizedKey . "')";
            }
            return $query;
        };

        $runDutyQuery = function ($query) {
            $results = $this->conn->query($query);
            $dutyReportResult = array();
            while ($row = $results->fetchObject()) {
                $row->{'devotee_photo'} = '';
                $dutyReportResult[] = $row;
            }
            return $dutyReportResult;
        };

        $query = $buildQuery(true);
        if($this->debug){echo $query; }
        $dutyReportResult = $runDutyQuery($query);

        // Compatibility fallback:
        // If no duty rows exist for the selected event, return across all events
        // so Office Duty report does not render as empty.
        if (empty($dutyReportResult)) {
            $fallbackQuery = $buildQuery(false);
            if($this->debug){echo $fallbackQuery; }
            $dutyReportResult = $runDutyQuery($fallbackQuery);
        }

        if($this->debug){echo "from API, after calling function: "; var_dump($dutyReportResult);}
        return $dutyReportResult;
    }
}
