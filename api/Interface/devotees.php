<?php

require_once dirname(__DIR__, 2) . '/includes/PhotoStorage.php';
require_once dirname(__DIR__, 2) . '/includes/PrintLog.php';

Class Devotee {

    //TODO: modify PROC_REPLACE_DEVOTEE_W_SEVA_I - it doesn't have Seva availability handled yet
    private $conn;
    private $table_name = "devotee";
    private $debug = false;
    //private $eventId = "";
// constructor with $db as database connection
    public function __construct($db) {
        $this->conn = $db;
    }

    private function parseIncludePhotos(array $requestData): bool
    {
        if (!isset($requestData['include_photos'])) {
            return false;
        }
        $v = strtolower(trim((string) $requestData['include_photos']));

        return in_array($v, ['1', 'true', 'yes', 'y'], true);
    }

    public function search($requestData){
        if($this->debug){var_dump($requestData); }
        if(!empty($requestData['mode'])){
                $includePhotos = $this->parseIncludePhotos($requestData);
                switch ($requestData['mode']){
                    case "KEY": //Devotee key supplied
                            return $this->getDetails(urldecode($requestData['key']), $requestData['eventId']);
                    break;

                    case "SET": //set query, like devotee without photo
                            return $this->searchDevotee($requestData['key'], $requestData['eventId'], $includePhotos);
                    break;
                
                    case "CUS": //Custom query                            
                            return $this->searchDevotee($requestData['key'], $requestData['eventId'], $includePhotos);
                    break;
                       
                    case "iSET": //set query, like devotee without photo
                            return $this->iSearchDevotee($requestData['key']);
                    break;
                
                    case "PCD": //Print Queue 
                            return $this->getDevoteeDetailsForPrint($requestData['key'], $requestData['eventId']);
                    break;
                
                    case "DAD": //Devotee Amenity Details 
                            return $this->getDevoteeAmenityDetails($requestData['key'], $requestData['eventId']);
                    break;

                    case "DPRO": //Depricated Devotee participation records (Old)
                        return $this->getParticipationRecord($requestData['key']);
                        break;

                    case "DPR": //Devotee participation records
                        return $this->getParticipationRecords($requestData['key']);
                        break;

                    case "DYN": //Dynamic search
                            return $this->dynamicSearchDevotee($requestData['key']);
                    break;
                
                    case "AOD": //Accommodation Occupier Devotees  
                            return $this->getDevoteesForAccommodation($requestData['key'], $requestData['eventId']);
                    break;
                
                    case "ADS": //Assigned Devotees to Seva
                            return $this->getDevoteesForSeva($requestData['key'], $requestData['eventId'], $includePhotos);
                    break;

                    case "DSA": //Devotees Seva Attendance
                            return $this->getDevoteesAttendance($requestData['key'], $requestData['eventId']);
                    break;

                    default :
                        return $this->getDetails($requestData['key']);                    
                    break;
                }            
            }
            else{
                return $this->getDetails($requestData['key']);                    
        }
    }
    
    private function getDetails($devotee_key, $eventId="" ){
        $res = array();
        $res['status'] = false;
        $res['message'] = '';
        $errormsg = "";
        $status = true;


        if (empty($devotee_key)) {
            $errormsg .= " Devotee Key is missing.";
            $status = false;
        }

        if($eventId == ""){
            $errormsg .= " Event ID is missing.";
            $status = false;
        }

        if ($status == false) {
            $res['status'] = $status;
            $res['message'] = $errormsg;
            return $res;
            die;
        }



        $query = "SELECT " .
                    "d.* " .
                    ", ds.Seva_ID " .
                    ", did.Devotee_ID_Image, did.Devotee_ID_XML " .
                    ", did.Devotee_ID_Type as DID_Devotee_ID_Type " .
                    ", dp.Photo_type, dp.Devotee_Photo, da.Accomodation_Key " .
                   // ", dd.Devotee_Address_1, dd.Devotee_Address_2, dd.Devotee_State, dd.Devotee_State as Devotee_station, dd.Devotee_Zip, dd.Devotee_Country, dd.Devotee_email " .
                    ", (SELECT count(*) from print_log where Devotee_Key= '" . $devotee_key . "' AND Event_Id = '". $eventId . "') print_count ".
                 "from " .
                    "devotee d " .
                    "left outer join devotee_id did on d.Devotee_Key=did.Devotee_Key " .
                    "left outer join devotee_photo dp on d.Devotee_Key=dp.Devotee_Key " .
                    "left outer join devotee_accomodation da on d.Devotee_key=da.Devotee_Key AND da.accommodation_event = '". $eventId . "' AND Accomodation_Status = 'Allocated'  " .
                    // "left outer join Devotee_Demographics dd on d.Devotee_Key = dd.Devotee_Key AND dd.Devotee_Address_Status = 'Current' " .
                    "left outer join devotee_seva ds on d.Devotee_key=ds.Devotee_Key AND ds.seva_event = '" . $eventId . "' AND Seva_Status = 'Assigned'  " .
                 "where " .
                    " d.Devotee_Key = '" . $devotee_key . "' ORDER BY da.Devotee_Accomodation_Update_Date_Time DESC LIMIT 1";
                    
        if($this->debug) {return $query; die;}

        $results = $this->conn->query($query);


        $DevoteeDetails = array();
        
        if(!empty($row = $results->fetchObject())){
            $key = (string) ($row->Devotee_Key ?? $devotee_key);
            $row->{'Devotee_Photo'} = PhotoStorage::legacyBase64Photo($this->conn, $key, $row->{'Devotee_Photo'});
            $row->{'Devotee_ID_Image'} = PhotoStorage::legacyBase64IdImage($this->conn, $key, $row->{'Devotee_ID_Image'});
            $DevoteeDetails=$row;
        }
        else{
            $DevoteeDetails['status'] = false;
            $DevoteeDetails['message'] = "Devotee details not found!";
            $DevoteeDetails['info'] = $results;
        }
        
        return $DevoteeDetails;
    }
    
    private function searchDevotee($requestData, $eventId = "", bool $includePhotos = false){
        $res = array();
        $res['status'] = false;
        $res['message'] = '';
        $errormsg = "";
        $status = true;
        if($this->debug){ echo "request data from search devotee: ", $requestData, " event Id: ", $eventId; }
        if (empty($requestData)) {
            $errormsg .= "Set key is missing.";
            $status = false;
        }

        if($eventId == ""){
            $errormsg .= " Event ID is missing.";
            $status = false;
        }

        if ($status == false) {
            $res['status'] = $status;
            $res['message'] = $errormsg;
            return $res;
            die;
        }
       
        if($this->debug){ echo "request data from search devotee: ", $requestData, " event Id: ", $eventId; }

        // Grid/reports: lightweight has_photo flags; images lazy-load via devoteePhoto.php (302→GCS or stream).
        // Print path (includePhotos) retains eager base64 load.
        $photoSelect = $includePhotos
            ? ', did.Devotee_ID_Image, dp.Devotee_Photo'
            : ", (dp.Devotee_Photo_Gcs_Path IS NOT NULL OR dp.Devotee_Photo IS NOT NULL) AS has_photo
               , (did.Devotee_ID_Image_Gcs_Path IS NOT NULL OR did.Devotee_ID_Image IS NOT NULL) AS has_id_image";
        $query = "select " .
                    "d.devotee_key, CONCAT(d.devotee_first_name, ' ', d.devotee_last_name) as Devotee_Name " .
                    ", d.devotee_station, d.devotee_cell_phone_number, d.devotee_status " .
                    $photoSelect .
                    ", d.devotee_station " .
                    ", d.Devotee_ID_Type " .
                    ", d.Devotee_ID_Number " .
                    ", am.accomodation_name " .
                 "from " .
                    " devotee d ".
                    " left outer join devotee_id did on d.Devotee_Key=did.Devotee_Key " .
                    " left outer join devotee_photo dp on d.Devotee_Key=dp.Devotee_Key " .
                    $this->searchAccommodationJoinSql($eventId);
                    // " left outer join Devotee_Demographics dd on d.devotee_key = dd.devotee_key";
                
        switch ($requestData){
            case "PWD": //Photo without Devotee Details                   
                $query = $query . 
                            " WHERE  " .
                                " (d.Devotee_First_Name is null OR d.Devotee_Last_Name is null)  " .
                              "AND  " .
                                "(did.Devotee_ID_Image is not null OR dp.Devotee_Photo is not null)  " .
                                "ORDER BY d.Devotee_Record_update_date_time Desc  LIMIT 50";
                    
                break;

            case "DWP": //Devotee records without Photo or ID
                $query = $query . 
                            " WHERE " .
                                "(d.Devotee_First_Name is not null OR d.Devotee_Last_Name is NOT null)  " .
                              "AND  " .
                                "(did.Devotee_ID_Image is null OR dp.Devotee_Photo is  null)  " .
                                "ORDER BY d.Devotee_Record_update_date_time Desc  LIMIT 50";
                break;
            
            case "CTP": //Card print queue
                $query = $query .
                            " LEFT OUTER JOIN card_print_log cpl on d.Devotee_Key = cpl.Devotee_Key "
                          . " WHERE cpl.Print_Status = 'A' and d.Devotee_Status != 'D' ORDER BY d.Devotee_Record_update_date_time Desc  LIMIT 50";
                
                break;
            
            case "TMP": // temporary Card print queue
                $query = $query .
                            " LEFT OUTER JOIN card_print_log cpl on d.Devotee_Key = cpl.Devotee_Key "
                          . " WHERE cpl.Print_Status = 'A' and d.Devotee_Status = 'D' and d.Devotee_Type = 'T' ORDER BY d.Devotee_Record_update_date_time Desc  LIMIT 50";

                break;



                case "RPC": //Recently printed Cards
                    $query = $query .
                                " LEFT OUTER JOIN card_print_archive cpl on d.Devotee_Key = cpl.Devotee_Key "
                              . " WHERE cpl.Print_Status = 'A'  ORDER BY cpl.Print_Requested_Date_Time DESC  LIMIT 50";
                    
                    break;

            case "AR": // Ashram residents only (excl. othr, LCL, OWNAR) — dashboard drill-down
                $query = $query .
                    " WHERE da.Devotee_Key IS NOT NULL"
                    . " AND LOWER(da.Accomodation_Key) NOT IN ('othr', 'lcl', 'ownar')"
                    . " ORDER BY d.Devotee_Record_update_date_time Desc LIMIT 500";
                break;

//            case "PCD": //Print Cards
//                $query = $query .
//                            " LEFT OUTER JOIN accommodation_master acm on da.accomodation_key = acm.accomodation_key "
//                          . " WHERE d.devotee_key in (" . $requestData . ")  ORDER BY d.Devotee_Record_update_date_time Desc";
//                
//                break;
                
            default : //Search based on user supplied search criteria
                $query = $query .
                            " WHERE " . $this->prepareSearchClause($requestData) . " ORDER BY d.Devotee_Record_update_date_time Desc  LIMIT 50"; 
                break;
        }
        if($this->debug){
            var_dump($query);
        }

                
        $results = $this->conn->query($query);
        
        $devoteeSearchResult = array();
        $seenKeys = [];
        $i = 0;
        while($row = $results->fetchObject()){
            $devoteeKey = (string) ($row->devotee_key ?? '');
            if ($devoteeKey === '' || isset($seenKeys[$devoteeKey])) {
                continue;
            }
            $seenKeys[$devoteeKey] = true;
            if ($includePhotos) {
                $row->{'Devotee_Photo'} = PhotoStorage::legacyBase64Photo($this->conn, $devoteeKey, $row->{'Devotee_Photo'} ?? null);
                $row->{'Devotee_ID_Image'} = PhotoStorage::legacyBase64IdImage($this->conn, $devoteeKey, $row->{'Devotee_ID_Image'} ?? null);
            } else {
                $row->{'Devotee_Photo'} = '';
                $row->{'Devotee_ID_Image'} = '';
                $row->{'has_photo'} = !empty($row->{'has_photo'});
                $row->{'has_id_image'} = !empty($row->{'has_id_image'});
            }
            $devoteeSearchResult[] = $row;
            $i = $i + 1;
        }
        //var_dump($devoteeSearchResult);
        if($i==0){
            $devoteeSearchResult['status'] = false;
            $devoteeSearchResult['message'] = "No record found!";
            $devoteeSearchResult['info'] = $results;
        }
        
        return $devoteeSearchResult;
    }

    /**
     * One allocated accommodation row per devotee per event (avoids duplicate search rows after merge/repoint).
     */
    private function searchAccommodationJoinSql(string $eventId): string
    {
        $qEvent = $this->conn->quote($eventId);

        return " left outer join (
                    SELECT da_inner.*
                    FROM devotee_accomodation da_inner
                    INNER JOIN (
                        SELECT Devotee_Key, MAX(Devotee_Accomodation_Update_Date_Time) AS max_upd
                        FROM devotee_accomodation
                        WHERE Accommodation_Event = {$qEvent} AND Accomodation_Status = 'Allocated'
                        GROUP BY Devotee_Key
                    ) da_pick ON da_inner.Devotee_Key = da_pick.Devotee_Key
                        AND da_inner.Devotee_Accomodation_Update_Date_Time = da_pick.max_upd
                    WHERE da_inner.Accommodation_Event = {$qEvent}
                        AND da_inner.Accomodation_Status = 'Allocated'
                ) da ON d.Devotee_Key = da.Devotee_Key
                left outer join accommodation_master am on da.accomodation_key = am.accomodation_key ";
    }
    
    private function iSearchDevotee($requestData){
        $res = array();
        $res['status'] = false;
        $res['message'] = '';
        $errormsg = "";
        $status = true;
        
        if (empty($requestData)) {
            $errormsg .= "Set key is missing.";
            $status = false;
        }
        
        if ($status == false) {
            $res['status'] = $status;
            $res['message'] = $errormsg;
            return $res;
            die;
        }
       
        $query = "select " .
                    "d.devotee_key, d.devotee_first_name, ' ', d.devotee_last_name " .
                    ", d.devotee_station, d.devotee_cell_phone_number, d.devotee_type, d.devotee_id_type " .
                    ", d.devotee_id_number, d.devotee_status, d.devotee_remarks " .
                    ", da.accomodation_key, am.accomodation_name " .
                    ", did.Devotee_ID_Image " .
                    ", dp.Devotee_Photo ".
                 "from " .
                    " devotee d ".
                    " left outer join devotee_id did on d.Devotee_Key=did.Devotee_Key " .
                    " left outer join devotee_photo dp on d.Devotee_Key=dp.Devotee_Key " .
                    " left outer join devotee_accomodation da on d.Devotee_Key=da.Devotee_key  " .
                        " AND da.Accomodation_year = YEAR(NOW()) AND da.Accomodation_Status = 'Allocated' " .
                    " left outer join accommodation_master am on da.accomodation_key=am.accomodation_key ";
                
                
        switch ($requestData){
            case "PWD": //Photo without Devotee Details                   
                $query = $query . 
                            " WHERE  " .
                                " (d.Devotee_First_Name is null OR d.Devotee_Last_Name is null)  " .
                              "AND  " .
                                "(did.Devotee_ID_Image is not null OR dp.Devotee_Photo is not null)  " .
                                "ORDER BY d.Devotee_Record_update_date_time Desc  LIMIT 50";
                    
                break;

            case "DWP": //Devotee records without Photo or ID
                $query = $query . 
                            " WHERE " .
                                "(d.Devotee_First_Name is not null OR d.Devotee_Last_Name is NOT null)  " .
                              "AND  " .
                                "(did.Devotee_ID_Image is null OR dp.Devotee_Photo is  null)  " .
                                "ORDER BY d.Devotee_Record_update_date_time Desc  LIMIT 50";
                break;
            
            case "CTP": //Card print queue
                $query = $query .
                            " LEFT OUTER JOIN card_print_log cpl on d.Devotee_Key = cpl.Devotee_Key "
                          . " WHERE cpl.Print_Status = 'A'  and d.Devotee_Status != 'D' ORDER BY d.Devotee_Record_update_date_time Desc  LIMIT 50";
                break;

            case "TMP": //Card print queue
                $query = $query .
                            " LEFT OUTER JOIN card_print_log cpl on d.Devotee_Key = cpl.Devotee_Key "
                          . " WHERE cpl.Print_Status = 'A' and d.Devotee_Status = 'D' ORDER BY d.Devotee_Record_update_date_time Desc  LIMIT 50";
                break;
            
//            case "PCD": //Print Cards
//                $query = $query .
//                            " LEFT OUTER JOIN accommodation_master acm on da.accomodation_key = acm.accomodation_key "
//                          . " WHERE d.devotee_key in (" . $requestData . ")  ORDER BY d.Devotee_Record_update_date_time Desc";
//                
//                break;
                
            default : //Search based on user supplied search criteria
                $query = $query .
                            " WHERE " . $this->prepareSearchClause($requestData) . " ORDER BY d.Devotee_Record_update_date_time Desc  LIMIT 50"; 
                break;
        }
        
        //var_dump($query);die;
                
        $results = $this->conn->query($query);
        
        $devoteeSearchResult = array();
        $i = 0;
        while($row = $results->fetchObject()){
            $row->{'Devotee_Photo'} = base64_encode($row->{'Devotee_Photo'});
            $row->{'Devotee_ID_Image'} = base64_encode($row->{'Devotee_ID_Image'});
            $devoteeSearchResult[] = $row;
            $i = $i + 1;
        }
        //var_dump($devoteeSearchResult);
        if($i==0){
            $devoteeSearchResult['status'] = false;
            $devoteeSearchResult['message'] = "No record found!";
            $devoteeSearchResult['info'] = $results;
        }
        
        return $devoteeSearchResult;
    }
    
    private function prepareSearchClause($requestData) {
        $searchClause = "";
        $subClauses = "";
        $subKey = "";
        $subValue = "";

        
        foreach(explode(",", $requestData) as $subClauses){
            
            if($this->debug){ echo "request data from prepare search clause: "; var_dump(explode("=", $subClauses)); }

            list($subKey, $subValue) = explode("=", $subClauses);
            
                switch ($subKey) {
                    // First Name
                    case "devotee_first_name":
                    case "first_name":
                    case "first name":
                    case "First Name":
                    case "FirstName":
                        $searchClause = $searchClause . "(d.devotee_first_name like '%" . str_replace(' ', '+', $subValue) . "%' OR d.devotee_first_name like '%" . $subValue . "%')  AND ";
                        break;
                    
                    // Last Name
                    case "devotee_last_name" :
                    case "last_name" :
                    case "last name" :
                    case "Last Name" :
                    case "LastName" :
                        $searchClause = $searchClause . "(d.devotee_last_name like '%" . str_replace(' ', '+', $subValue) . "%' OR d.devotee_last_name like '%" . $subValue . "%') AND ";
                        break;
                    
                    // Station
                    case "devotee_station" :
                    case "Station" :
                    case "Devotee Station" :
                    case "DevoteeStation" :
                    case "devotee_state":
                    case "State" :
                    case "Devotee State" :
                    case "DevoteeState" :
                        $searchClause = $searchClause . "(d.devotee_station like '%" . str_replace(' ', '+', $subValue) . "%' OR d.devotee_station like '%" .  $subValue . "%') AND ";
                        break;

                    // Status
                    case "devotee_status" :
                    case "Status" :
                    case "Devotee Status" :
                    case "DevoteeStatus" :
                        $searchClause = $searchClause . "(d.devotee_status = '" . str_replace(' ', '+', $subValue) . "' OR d.devotee_status = '" .  $subValue . "' ) AND ";
                        break;

                    // Cell Phone Number
                    case "devotee_cell_phone_number" :
                    case "cell phone number" :
                    case "devotee cell phone number" :
                    case "Cell Phone Number" :
                    case "Devotee Cell Phone Number" :
                        $searchClause = $searchClause . "(d.devotee_cell_phone_number like '%" . str_replace(' ', '', $subValue) . "%' OR d.devotee_cell_phone_number like '%" . $subValue . "%') AND ";
                        break;
                    
                    // Remarks
                    case "devotee_remarks" :
                    case "remarks" :
                    case "devotee_remark" :
                    case "remark" :
                    case "Devotee Remark" :
                        $searchClause = $searchClause . "(d.devotee_remarks like '%" . str_replace(' ', '+', $subValue) . "%' OR d.devotee_remarks like '%" . $subValue . "%') AND ";
                        break;                    
                    
                    // Devotee key
                    case "devotee_key" :
                    case "Devotee_Key" :
                    case "Devotee Key" :
                        $searchClause = $searchClause . "(d.devotee_key = '" . str_replace(' ', '+', $subValue) . "' OR d.devotee_key = '" . $subValue . "') AND ";
                        break;

                    // ID Number
                    case "devotee_id_number" :
                    case "Devotee_ID_Number" :
                    case "id_number" :
                    case "ID_Number" :
                    case "Devotee ID Number" :
                        $searchClause = $searchClause . "(d.devotee_id_number like '%" . str_replace(' ', '+', $subValue) . "%' OR d.devotee_id_number like '%" . $subValue . "%' ) AND ";
                        break; 
                    
                    // Accommodation
                    case "devotee_accommodation" :
                    case "devotee_accommodation_key" :
                    case "Devotee Accommodation Key" :
                    case "DevoteeAccommodationKey" :
                    case "Accommodation" :
                    case "accommodation" :                        
                    case "Accommodation Key" :  
                    case "devotee_accomodation" :
                    case "devotee_accomodation_key" :
                    case "Devotee Accomodation Key" :
                    case "DevoteeAccomodationKey" :
                    case "Accomodation" :
                    case "accomodation" :                        
                    case "Accomodation Key" :
                        if (strpos($subValue, '|') !== false) {
                            $keys = array_filter(array_map(static function ($k) {
                                return preg_replace('/[^A-Za-z0-9]/', '', trim($k));
                            }, explode('|', $subValue)));
                            if ($keys !== []) {
                                $in = implode("','", $keys);
                                $searchClause .= "(da.accomodation_key IN ('" . $in . "')) AND ";
                            }
                        } else {
                            $safeKey = preg_replace('/[^A-Za-z0-9]/', '', $subValue);
                            $searchClause .= "(da.accomodation_key = '" . $safeKey . "') AND ";
                        }
                        break;
                }           
        }
        
        $searchClause = substr($searchClause, 0, strlen($searchClause)-5);
        //var_dump($searchClause);
        //$searchClause = $requestData;
        return $searchClause;
    }

    private function dynamicSearchDevotee($requestData) {
    
        try {
                $query = "SELECT * FROM devotee WHERE "
                        . "`Devotee_First_Name` like :requestData OR "
                        . "`Devotee_Last_Name` like :requestData OR "
                        . "`Devotee_Station` like :requestData OR "
                        . "`Devotee_ID_Number` like :requestData OR "
                        . "`Devotee_Cell_Phone_Number` like :requestData";

                $stmt = $this->conn->prepare($query);
                $val = "%$requestData%";
                $stmt->bindParam(':requestData', $val , PDO::PARAM_STR);
                $stmt->execute();

                $Count = $stmt->rowCount();
                //echo " Total Records Count : $Count .<br>" ;

                $result ="" ;
                if ($Count  > 0){
                            while($data=$stmt->fetch(PDO::FETCH_ASSOC)) {
                               $result = $result .'<a href="addDevoteeI.php?devotee_key='.$data['Devotee_Key'].'"><div class="search-result">'.$data['Devotee_First_Name'].' '.$data['Devotee_Last_Name'].' - ('.$data['Devotee_Station'].') - '.$data['Devotee_Cell_Phone_Number'].'</div></a>';
                            }
                        return $result ;
                    }
                }
                catch (PDOException $e) {
                        echo 'Connection failed: ' . $e->getMessage();
                }
    }

    /**
     * Parse comma-separated devotee keys from UI or legacy prints (may include extra quotes / HTML entities).
     *
     * @return list<string>
     */
    private function normalizeDevoteeKeysFromRequest(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        if (strpos($raw, '&#') !== false || strpos($raw, '&quot;') !== false) {
            $raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $parts = preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            $p = trim($p, "'\"`\s");
            $p = strip_tags($p);
            if ($p !== '' && preg_match('/^[A-Za-z0-9_-]{3,64}$/', $p)) {
                $out[] = $p;
            }
        }

        return array_values(array_unique($out));
    }
    
    private function getDevoteeDetailsForPrint($requestData, $eventId = ""){
        $res = array();
        $res['status'] = false;
        $res['message'] = '';
        $errormsg = "";

        $keysNormalized = $this->normalizeDevoteeKeysFromRequest((string) $requestData);

        if (empty($keysNormalized)) {
            $errormsg .= "Devotee keys for printing not supplied.";
            $res['status'] = false;
            $res['message'] = $errormsg;
            return $res;
        }

        $inClause = implode(',', array_map(function ($k) {
            return $this->conn->quote($k);
        }, $keysNormalized));

        $query = "select " .
                    "d.devotee_key, devotee_first_name, d.devotee_last_name " .
                    ", d.devotee_station, d.devotee_status, d.devotee_cell_phone_number " .
                    ", d.Devotee_Referral, d.Devotee_Type " .
                    ", am.accomodation_name " .
                    ", dp.Devotee_Photo ".
                 "from " .
                    " devotee d ".
                    " left outer join devotee_id did on d.Devotee_Key=did.Devotee_Key " .
                    " left outer join devotee_photo dp on d.Devotee_Key=dp.Devotee_Key " .
                    $this->searchAccommodationJoinSql($eventId) .
                 "where " .
                    "d.devotee_key in (" . $inClause . ") ORDER BY d.Devotee_Record_update_date_time Desc" ;
                
        
           
       if($this->debug){
           echo $query; die;
       }
                
        $results = $this->conn->query($query);

        $devoteeSearchResult = array();
        $seenKeys = [];
        $i = 0;
        if ($results !== false) {
            while ($row = $results->fetchObject()) {
                $devoteeKey = (string) ($row->devotee_key ?? '');
                if ($devoteeKey === '' || isset($seenKeys[$devoteeKey])) {
                    continue;
                }
                $seenKeys[$devoteeKey] = true;
                $row->{'Devotee_Photo'} = PhotoStorage::legacyBase64Photo($this->conn, $devoteeKey, $row->{'Devotee_Photo'} ?? '');

                $devoteeSearchResult[]=$row;
                $i = $i+1;
            }
        }
        //var_dump($devoteeSearchResult);die;
        if($i==0){
            $devoteeSearchResult['status'] = false;
            $devoteeSearchResult['message'] = "No record found!";
            $devoteeSearchResult['info'] = '';
        }

        return $devoteeSearchResult;
    }
    
    private function getDevoteesForAccommodation($requestData = "", $eventId = ""){
        $res = array();
        $res['status'] = false;
        $res['message'] = '';
        $errormsg = "";
        $status = true;
                
        if ($eventId == "") {
            $errormsg .= "Event ID not supplied.";
            $status = false;           
        }
       
                
        if ($status == false) {
            $res['status'] = $status;
            $res['message'] = $errormsg;
            return $res;
            die;
        }
       /*
        $query = "select 
                    d.devotee_key, devotee_first_name, d.devotee_last_name, CONCAT(d.devotee_first_name, ' ', d.devotee_last_name) as Devotee_Name 
                    , d.devotee_station, d.devotee_cell_phone_number 
                    , acm.accomodation_name 
                    , did.Devotee_ID_Image 
                    , dp.Devotee_Photo
                 from 
                    devotee d 
                     left outer join devotee_id did on d.Devotee_Key=did.Devotee_Key 
                     left outer join devotee_photo dp on d.Devotee_Key=dp.Devotee_Key 
                     left outer join devotee_accomodation da on d.Devotee_Key=da.Devotee_key AND da.Accomodation_Status = 'Allocated' ";

*/

        $includeImages = ($requestData != "");

        $query = "SELECT 
                    d.devotee_key
                    , CONCAT(d.devotee_first_name, ' ', d.devotee_last_name) AS Devotee_Name 
                    , REPLACE(REPLACE(IFNULL(CONCAT(d.devotee_address_1, ', ', d.devotee_address_2, ', ', d.devotee_station, ', ', d.devotee_state, '-', d.devotee_zip, ', ', d.devotee_country), '--'), '+', ' '), ', ,', ',') AS Devotee_address
                    , d.devotee_station
                    , IFNULL(CONCAT('(', SUBSTR(d.devotee_cell_phone_number, 1, 3),')-', SUBSTR(d.devotee_cell_phone_number, 4, 3), '-', SUBSTR(d.devotee_cell_phone_number, 7)),  '(###)-###-####') AS devotee_cell_phone_number
                    , acm.accomodation_name   
                    , da.accomodation_key
                    , " . ($includeImages ? "did.Devotee_ID_Image" : "''") . " AS Devotee_ID_Image
                    , " . ($includeImages ? "dp.Devotee_Photo" : "''") . " AS Devotee_Photo
                    , DATE_FORMAT(da.arrival_date_time,'%d/%m/%Y') AS arrival_date_time 
                    , IF (d.devotee_gender = 'M', 'Male', IF (d.devotee_gender = 'F', 'Female', d.devotee_gender)) AS devotee_gender
                    , d.devotee_id_type
                    , d.devotee_id_number
                    , IFNULL(d2a.allocations, '--') AS allocations
                    , IFNULL(dr1.remarks, '--') AS remarks
                 FROM 
                    devotee d 
                    " . ($includeImages ? "LEFT OUTER JOIN devotee_id did ON d.Devotee_Key = did.Devotee_Key" : "") . "
                    " . ($includeImages ? "LEFT OUTER JOIN devotee_photo dp ON d.Devotee_Key = dp.Devotee_Key" : "") . "
                    left outer join (SELECT daa.devotee_key, daa.allocation_event, GROUP_CONCAT(daa.amenity_key, ' - ', daa.amenity_quantity ORDER BY daa.amenity_key ASC SEPARATOR '; ' ) AS Allocations FROM devotee_amenities_allocation daa WHERE daa.allocation_event = '" . $eventId. "' GROUP BY daa.allocation_event, daa.devotee_key) d2a ON d.Devotee_Key=d2a.devotee_key 
                    left outer join (SELECT dr.remark_event, dr.devotee_key, replace(group_concat(dr.remark_type, ': ', dr.rating, ' - ', dr.remark SEPARATOR ' <br> '), '||', '<br>') AS Remarks FROM devotee_remarks dr WHERE dr.remark_event = '" . $eventId. "' GROUP BY dr.remark_event, dr.devotee_key) dr1 ON d.devotee_key = dr1.devotee_key
                    left outer join devotee_accomodation da on d.Devotee_Key=da.Devotee_key AND da.Accomodation_Status = 'Allocated' AND da.Accommodation_event = '" . $eventId. "' 
                    left outer join accommodation_master acm on da.accomodation_key = acm.accomodation_key AND da.Accommodation_event = '" . $eventId . "'";

                    
        if($requestData != ""){
            $requestData = trim(urldecode($requestData));
            if(substr($requestData, 0) == "," or substr($requestData, -1) == ",") {
                $requestData = trim($requestData, ",");
            }
            $requestData = str_replace(",", "','", $requestData);
            $query = $query . " WHERE da.Accomodation_key IN ('" . $requestData . "') ORDER BY da.accomodation_key DESC"; 
        }
        else {
            $query = $query . " WHERE da.Accomodation_key IS NOT NULL ORDER BY da.accomodation_key ASC" ;
        }

        if($this->debug){
            var_dump($query);die;
        }

                
        $results = $this->conn->query($query);
        
        $devoteeSearchResult = array();
        $i = 0;
        while($row = $results->fetchObject()){
            $rawPhoto = $row->{'Devotee_Photo'} ?? null;
            $rawId = $row->{'Devotee_ID_Image'} ?? null;
            $row->{'Devotee_Photo'} = ($rawPhoto !== null && $rawPhoto !== '') ? base64_encode((string) $rawPhoto) : '';
            $row->{'Devotee_ID_Image'} = ($rawId !== null && $rawId !== '') ? base64_encode((string) $rawId) : '';
            $devoteeSearchResult[]=$row;
            $i = $i+1;
        }
        //var_dump($devoteeSearchResult);die;
        if($i==0){
            $devoteeSearchResult['status'] = false;
            $devoteeSearchResult['message'] = "No record found!";
            $devoteeSearchResult['info'] = $results;
        }
        
        return $devoteeSearchResult;
    }
    
    private function getDevoteesForSeva($requestData, $eventId = "", bool $includePhotoBlobs = false){
        $res = array();
        $res['status'] = false;
        $res['message'] = '';
        $errormsg = "";
        $status = true;
        
        /* //Seva ID can be empty now, meaning all sevas
        if (empty($requestData)) {
            $errormsg .= "Seva id not supplied.";
            $status = false;
        }
        
        if ($status == false) {
            $res['status'] = $status;
            $res['message'] = $errormsg;
            return $res;
            die;
        }
        */
       
        /* $query = "select " .
                    "d.devotee_key, devotee_first_name, d.devotee_last_name, CONCAT(d.devotee_first_name, ' ', d.devotee_last_name) as Devotee_Name 
                    , d.devotee_station
                    , d.devotee_cell_phone_number 
                    , did.Devotee_ID_Image 
                    , dp.Devotee_Photo 
                    , sm.Seva_description
                 from 
                     devotee d 
                     left outer join devotee_id did on d.Devotee_Key=did.Devotee_Key 
                     left outer join devotee_photo dp on d.Devotee_Key=dp.Devotee_Key 
                     left outer join devotee_seva ds ON d.Devotee_Key = ds.Devotee_Key AND ds.Seva_Status = 'Assigned' " ;
        */
        $filterBySeva = (($requestData != "") and ($requestData != "All"));

        $query = "select " .
                    "d.devotee_key, devotee_first_name, d.devotee_last_name, CONCAT(d.devotee_first_name, ' ', d.devotee_last_name) as Devotee_Name 
                    , d.devotee_station 
                    , IFNULL(CONCAT('(', SUBSTR(d.devotee_cell_phone_number, 1, 3),')-', SUBSTR(d.devotee_cell_phone_number, 4, 3), '-', SUBSTR(d.devotee_cell_phone_number, 7)),  '(###)-###-####') AS devotee_cell_phone_number";
        if ($includePhotoBlobs) {
            $query = $query . ", dp.Devotee_Photo ";
        } else {
            $query = $query . ", '' AS Devotee_Photo
                    , (dp.Devotee_Photo_Gcs_Path IS NOT NULL OR dp.Devotee_Photo IS NOT NULL) AS has_photo ";
        }
        $query = $query . "
                    , sm.Seva_description
                 from 
                     devotee d ";
        $query = $query . " left outer join devotee_photo dp on d.Devotee_Key=dp.Devotee_Key ";
        $query = $query . "
                     left outer join devotee_seva ds ON d.Devotee_Key = ds.Devotee_Key AND ds.Seva_Status = 'Assigned' " ;
        if($eventId <> ""){
            $query = $query .  "AND ds.Seva_Event = '" . $eventId . "' ";
        }

        $query = $query . " left outer join seva_master sm on ds.seva_id = sm.seva_id ";
        $query = $query . "WHERE ds.seva_id is not null " ;

        if($filterBySeva){            
                $requestData = trim(urldecode($requestData));
                if(substr($requestData, 0) == "," or substr($requestData, -1) == ",") {
                    $key = trim($requestData, ",");
                }
                $requestData = str_replace(",", "','", $requestData);

            //$query = $query . " AND ds.Seva_ID = '" . $requestData . "'" ;
            $query = $query . " AND ds.Seva_ID IN ('" . $requestData . "')" ;
        }
        $query = $query . " ORDER BY ds.Seva_ID Desc" ;

        if($this->debug) {var_dump($query);}
                
        $results = $this->conn->query($query);
        
        $devoteeSearchResult = array();
        $i = 0;
        while($row = $results->fetchObject()){
            if ($includePhotoBlobs) {
                $devoteeKey = (string) ($row->devotee_key ?? '');
                $row->{'Devotee_Photo'} = PhotoStorage::legacyBase64Photo($this->conn, $devoteeKey, $row->{'Devotee_Photo'} ?? null);
            } else {
                $row->{'Devotee_Photo'} = '';
                $row->{'has_photo'} = !empty($row->{'has_photo'});
            }
            $devoteeSearchResult[]=$row;
            $i = $i+1;
        }
        //var_dump($devoteeSearchResult);die;
        if($i==0){
            $devoteeSearchResult['status'] = false;
            $devoteeSearchResult['message'] = "No record found!";
            $devoteeSearchResult['info'] = $results;
        }
        
        return $devoteeSearchResult;
    }
    
    private function getDevoteesAttendance($requestData, $eventId = ""){
        $res = array();
        $res['status'] = false;
        $res['message'] = '';
        $errormsg = "";
        $status = true;
        
        /* //Seva ID can be empty now, meaning all sevas
        if (empty($requestData)) {
            $errormsg .= "Seva id not supplied.";
            $status = false;
        }
        
        if ($status == false) {
            $res['status'] = $status;
            $res['message'] = $errormsg;
            return $res;
            die;
        }
        */
       
        $includePhotos = (($requestData != "") and ($requestData != "All"));

        $query = "select " .
                    "d.devotee_key, devotee_first_name, d.devotee_last_name, CONCAT(d.devotee_first_name, ' ', d.devotee_last_name) as Devotee_Name 
                    , d.devotee_station
                    , IFNULL(CONCAT('(', SUBSTR(d.devotee_cell_phone_number, 1, 3),')-', SUBSTR(d.devotee_cell_phone_number, 4, 3), '-', SUBSTR(d.devotee_cell_phone_number, 7)),  '(###)-###-####') AS devotee_cell_phone_number ";
                    //, did.Devotee_ID_Image 
        if ($includePhotos) {
            $query = $query . ", (dp.Devotee_Photo_Gcs_Path IS NOT NULL OR dp.Devotee_Photo IS NOT NULL) AS has_photo ";
        }
        $query = $query . ", '' AS Devotee_Photo 
                    , sm.Seva_description
                    , sm.seva_id
                    , IF(ISNULL(da.rating), '--', IF(da.rating=5, 'Present','Absent')) AS attendance
                 from 
                     devotee d ";
                     //left outer join devotee_id did on d.Devotee_Key=did.Devotee_Key 
        $query = $query . " left outer join devotee_photo dp on d.Devotee_Key=dp.Devotee_Key ";
        $query = $query . " left outer join devotee_seva ds ON d.Devotee_Key = ds.Devotee_Key AND ds.Seva_Status = 'Assigned' " ;
                        if($eventId <> ""){
                            $query = $query .  "AND ds.Seva_Event = '" . $eventId . "' ";
                        }

        $query = $query . " left outer join devotee_attendance da ON d.devotee_key=da.devotee_key AND ds.seva_id=da.seva_id AND da.attendance_date = CURDATE() 
                            left outer join seva_master sm on ds.seva_id = sm.seva_id ";
        $query = $query . "WHERE ds.seva_id is not null " ;

        if($includePhotos){            
                $requestData = trim(urldecode($requestData));
                if(substr($requestData, 0) == "," or substr($requestData, -1) == ",") {
                    $key = trim($requestData, ",");
                }
                $requestData = str_replace(",", "','", $requestData);

            //$query = $query . " AND ds.Seva_ID = '" . $requestData . "'" ;
            $query = $query . " AND ds.Seva_ID IN ('" . $requestData . "')" ;
        }
        else { //if printing for all sevas, don't include unassigned sevas in the report
            $query = $query . " AND ds.Seva_ID NOT IN ('UN')" ;
        }
        $query = $query . " ORDER BY ds.Seva_ID Desc" ;

        if($this->debug) {var_dump($query);}
                
        $results = $this->conn->query($query);
        
        $devoteeSearchResult = array();
        $i = 0;
        while($row = $results->fetchObject()){
            $row->{'Devotee_Photo'} = '';
            if ($includePhotos) {
                $row->{'has_photo'} = !empty($row->{'has_photo'});
            }
            $devoteeSearchResult[]=$row;
            $i = $i+1;
        }
        //var_dump($devoteeSearchResult);die;
        if($i==0){
            $devoteeSearchResult['status'] = false;
            $devoteeSearchResult['message'] = "No record found!";
            $devoteeSearchResult['info'] = $results;
        }
        
        return $devoteeSearchResult;
    }

    private function getDevoteeAmenityDetails($devoteeKey = "", $eventId = ""){
        $res = array();
        $res['status'] = false;
        $res['message'] = '';
        $errormsg = "";
        $status = true;     

        if ($devoteeKey == "") {
            $errormsg .= "Devotee key not supplied.";
            $status = false;
        }
        else {
            $devoteeKey = htmlspecialchars(strip_tags($devoteeKey));
        }

        if ($eventId == "") {
            $errormsg .= "Event ID not supplied.";
            $status = false;
        }
        else {
            $eventId = htmlspecialchars(strip_tags($eventId));
        }
        
        if ($status == false) {
            $res['status'] = $status;
            $res['message'] = $errormsg;
            return $res;
            die;
        }
       
        $query = "SELECT  AM.Amenity_Key, AM.Amenity_Name, IFNULL(DAA.Amenity_Quantity,0) AS Amenity_Quantity, IFNULL(AA.Available_Count, 0) AS Available_Count 
                    FROM amenity_master AM
                    LEFT OUTER JOIN devotee_amenities_allocation DAA ON DAA.Amenity_key = AM.Amenity_key                         
                        AND DAA.Amenity_Quantity <> 0 AND DAA.Devotee_Key = '" . $devoteeKey . "'  
                        AND DAA.allocation_event = '" . $eventId . "' 
                    LEFT OUTER JOIN amenities_availability AA ON AM.Amenity_Key = AA.Amenity_Key" ;
                    // ORDER BY Amenity_Allocation_Date_Time DESC" ;
        /*
        $query = "SELECT " .
                    "AM.`Amenity_Key`, " . 
                    "AM.`Amenity_Name`, " . 
                    "IFNULL(DAA.`Amenity_Quantity`,0) AS Amenity_Quantity, " .
                    "IFNULL(AA.Available_Count, 0) AS Available_Count " .
                "FROM " . 
                    "`amenity_master` AM " .
                "LEFT OUTER JOIN `devotee_amenities_allocation` DAA ON " .
                    "DAA.Amenity_key = AM.Amenity_key " .
                "AND " .
                    "DAA.Devotee_Key = '" . $requestData . "' AND " .
                    "DAA.`Amenity_Quantity` <> 0 AND " .
                    "DAA.`Amenity_Allocation_Year` = YEAR(NOW()) " .
                "LEFT OUTER JOIN amenities_availability AA ON " .
                    "AM.Amenity_Key = AA.Amenity_Key " .
                "ORDER BY " .
                    "`Amenity_Allocation_Date_Time` DESC" ;
        */
           // var_dump($query);
        $results = $this->conn->query($query);
        
        $devoteeAmenityResult = array();
        $i = 0;
        while($row = $results->fetchObject()){
            $devoteeAmenityResult[]=$row;
            $i = $i+1;
        }
        //var_dump($devoteeSearchResult);die;
        if($i==0){
            $devoteeAmenityResult['status'] = false;
            $devoteeAmenityResult['message'] = "No record found!";
            $devoteeAmenityResult['info'] = $results;
        }
        
        return $devoteeAmenityResult;
    }

    private function getParticipationRecords($requestData){
        $query = array();
        $participationResults = array();


        //1. Devotee Accommodation Records
        $query[0] = "SELECT
                        da.devotee_key,
                        IFNULL(em.event_description, '--') as 'Event',
                        IFNULL(am.accomodation_name, '--') as 'Accommodation' , 
                        IFNULL(DATE_FORMAT(da.arrival_date_time, '%M %d %Y'), '--') as 'OccupiedOn', 
                        IFNULL(DATE_FORMAT(da.Departure_Date_Time, '%M %d %Y'), '--') as 'VacatedOn',                         
                        IFNULL(da.Accommodation_Event, '--') as 'EventID' 
                    FROM
                        devotee_accomodation da 
                        LEFT OUTER JOIN accommodation_master am ON da.accomodation_key = am.Accomodation_Key                        
                        LEFT OUTER JOIN event_master em ON da.Accommodation_Event = em.Event_id
                    WHERE
                        am.Accomodation_Name is not null   
                            AND da.devotee_key = '" . $requestData . "'
                    ORDER BY 
                        da.arrival_date_time  DESC
                        LIMIT 50";

        //2. Devotee Seva Records
        /*
        $query[1] = "SELECT
                        ds.devotee_key,
                        IFNULL(em.event_description, '--') as 'Event',
                        IFNULL(sm.seva_description, '-unknown-') as 'Seva',  
                        IFNULL(DATE_FORMAT(ds.assignment_date_time, '%M %d %Y'), '--') as 'AssignedOn',
                        IFNULL(ds.seva_event, '--') as 'EventID' 
                    FROM
                        devotee_seva ds 
                        LEFT OUTER JOIN seva_master sm ON ds.seva_id = sm.seva_id
                        LEFT OUTER JOIN event_master em ON ds.seva_event = em.Event_id
                    WHERE                        
                        ds.devotee_key = '" . $requestData . "'
                    ORDER BY 
                        ds.assignment_date_time  DESC
                        LIMIT 50 ";
        */

        $query[1] = "SELECT
                        ds.devotee_key,
                        IFNULL(em.event_description, '--') as 'Event',
                        IFNULL(sm.seva_description, '-unknown-') as 'Seva',  
                        IFNULL(DATE_FORMAT(ds.assignment_date_time, '%M %d %Y'), '--') as 'AssignedOn',
                        IFNULL(ds.seva_event, '--') as 'EventID' ,
                        IFNULL(da2.Attendance, '--') as Attendance
                    FROM
                        devotee_seva ds 
                        LEFT OUTER JOIN seva_master sm ON ds.seva_id = sm.seva_id
                        LEFT OUTER JOIN event_master em ON ds.seva_event = em.Event_id
                        LEFT OUTER JOIN (SELECT da.devotee_key, da.seva_id,  da.seva_event, GROUP_CONCAT(da.attendance_date, '=> ', da.remark ORDER BY da.attendance_date ASC SEPARATOR ' <br> ') AS Attendance 
                        FROM devotee_attendance da GROUP BY da.devotee_key, da.seva_id, da.seva_event)  da2 ON ds.devotee_key = da2.devotee_key AND ds.seva_id = da2.seva_id AND ds.seva_event = da2.seva_event
                    WHERE                        
                        ds.devotee_key =  '" . $requestData . "'
                    ORDER BY 
                        da2.seva_event  DESC
                        LIMIT 50 ";


        //3. Devotee Amenity Records
        $query[2] = "SELECT em.event_description AS Event, GROUP_CONCAT(am.amenity_name, ' - ', da2.amenity_quantity ORDER BY am.amenity_name ASC SEPARATOR '; ' ) AS Allocations 
                    FROM devotee_amenities_allocation da1 
                    LEFT OUTER JOIN devotee_amenities_allocation da2 ON da1.amenity_key = da2.amenity_key  AND da1.devotee_key = da2.devotee_key AND da1.allocation_event = da2.allocation_event
                    LEFT OUTER JOIN amenity_master am ON da1.amenity_key = am.amenity_key
                    LEFT OUTER JOIN event_master em ON da1.allocation_event = em.event_id
                    WHERE da1.devotee_key = '" .$requestData . "'
                    GROUP BY da1.allocation_event ";


        //4. Devotee Remarks 
        $query[3] = "SELECT em.event_description AS Event, group_concat(dr.remark_type, ': ', dr.rating, ' - ', dr.remark SEPARATOR ' || ') AS Remarks 
                    FROM devotee_remarks dr
                    LEFT OUTER JOIN event_master em ON dr.remark_event = em.event_id
                    WHERE dr.devotee_key = '" .$requestData . "'
                    GROUP BY dr.remark_event
                    ORDER BY em.event_description DESC ";

        if ($this->debug) {            var_dump($query);        }
        for ($i = 0; $i < sizeof($query); $i++) {

            $results = $this->conn->query($query[$i]);
            $devoteeParticipationResult = array();
            $j = 0;
            while($row = $results->fetchObject()){
                $devoteeParticipationResult[]=$row;
                $j = $j+1;
            }
            $participationResults[$i] = $devoteeParticipationResult;
            if($this->debug){var_dump($row);}            
        }

        return $participationResults;
    }
    private function getParticipationRecord($requestData){
        $res = array();
        $res['status'] = false;
        $res['message'] = '';
        $errormsg = "";
        $status = true;

        if (empty($requestData)) {
            $errormsg .= "Devotee keys not supplied for fetching Participation Records.";
            $status = false;
        }

        if ($status == false) {
            $res['status'] = $status;
            $res['message'] = $errormsg;
            return $res;
            die;
        }

        $query =    "SELECT
                        da.devotee_key,
                        IFNULL(em.event_description, '--') as 'Event',
                        IFNULL(am.accomodation_name, '--') as 'Accommodation' , 
                        IFNULL(DATE_FORMAT(da.arrival_date_time, '%M %d %Y'), '--') as 'OccupiedOn', 
                        IFNULL(DATE_FORMAT(da.Departure_Date_Time, '%M %d %Y'), '--') as 'VacatedOn', 
                        IFNULL(sm.seva_description, '-unknown-') as 'Seva',  
                        IFNULL(DATE_FORMAT(ds.assignment_date_time, '%M %d %Y'), '--') as 'AssignedOn',
                        IFNULL(da.Accommodation_Event, ds.seva_event) as 'EventID' 
                    FROM
                        devotee d
                        LEFT OUTER JOIN devotee_accomodation da ON d.devotee_key = da.Devotee_Key
                        LEFT OUTER JOIN accommodation_master am ON da.accomodation_key = am.Accomodation_Key
                        LEFT OUTER JOIN devotee_seva ds ON d.Devotee_Key = ds.Devotee_Key AND da.Accommodation_Event = ds.Seva_Event
                        LEFT OUTER JOIN seva_master sm ON ds.seva_id = sm.seva_id
                        LEFT OUTER JOIN event_master em ON da.Accommodation_Event = em.Event_id
                    WHERE
                        (am.Accomodation_Name is not null  OR sm.Seva_Description is not null) 
                            AND d.devotee_key = '" . $requestData . "'" .
                    " ORDER BY 
                        da.Accommodation_Event,ds.seva_event  ASC
                        LIMIT 50";

        if($this->debug){     var_dump($query); }

        $results = $this->conn->query($query);

        if($this->debug){     var_dump($results);         }
        $devoteeParticipationResult = array();
        $i = 0;
        while($row = $results->fetchObject()){
            $devoteeParticipationResult[]=$row;
            $i = $i+1;
        }

        if($this->debug){     var_dump($devoteeParticipationResult);        }

        if($i==0){
            $devoteeParticipationResult['status'] = false;
            $devoteeParticipationResult['message'] = "No record found!";
            $devoteeParticipationResult['info'] = $results;
        }

        return $devoteeParticipationResult;
    }

    public function upsertDevotee($requestData) {
        $res = array();
        $res['status'] = false;
        $res['message'] = '';
        $res['info']='';
        $errormsg = "";
        $status = true;


        //Devotee Type
        if (empty($requestData['devotee_type'])) {
            $errormsg .= " Devotee Type is missing.";
            $status = false;
        }
        else{
            $Devotee_Type=htmlspecialchars(strip_tags($requestData['devotee_type']));
        }

        //Devotee Firsl Name
        if (empty($requestData['devotee_first_name'])) {
            $errormsg .= " First name missing.";
            $status = false;
        }
        else{
            $Devotee_First_Name=htmlspecialchars(strip_tags($requestData['devotee_first_name']));
        }

        //Devotee_Last_Name
        if (empty($requestData['devotee_last_name'])) {
            $errormsg .= " Devotee last name misssing.";
            $status = false;
        }
        else {
            $Devotee_Last_Name=htmlspecialchars(strip_tags($requestData['devotee_last_name']));
        }

        //Devotee Gender
        if (empty($requestData['devotee_gender'])){
            $Devotee_Gender="";
        }
        else{
            $Devotee_Gender=htmlspecialchars(strip_tags($requestData['devotee_gender']));
        }

        //Devotee DOB
        require_once dirname(__DIR__, 2) . '/includes/kdms_dob.php';
        if (empty($requestData['devotee_dob'])) {
            $Devotee_DOB = '1900-01-01';
        } else {
            $rawDob = htmlspecialchars(strip_tags((string) $requestData['devotee_dob']));
            $normalizedDob = kdms_normalize_devotee_dob($rawDob);
            if ($normalizedDob === null) {
                $Devotee_DOB = '';
                $errormsg .= ' Date of Birth is invalid.';
                $status = false;
            } else {
                $Devotee_DOB = $normalizedDob;
            }
        }

        //Devotee ID Type
        if (empty($requestData['devotee_id_type'])){
            $Devotee_ID_Type="";
        }
        else{
            $Devotee_ID_Type=htmlspecialchars(strip_tags($requestData['devotee_id_type']));
        }

        //Devotee ID Number
        if (empty($requestData['devotee_id_number'])){
            $Devotee_ID_Number="";
        }
        else{
            $Devotee_ID_Number=htmlspecialchars(strip_tags($requestData['devotee_id_number']));
        }

        //Devotee Address 1
        if (empty($requestData['devotee_address_1'])){
            $Devotee_Address_1="";
        }
        else {
            $Devotee_Address_1=htmlspecialchars(strip_tags($requestData['devotee_address_1']));
        }

        //Devotee Address 2
        if (empty($requestData['devotee_address_2'])){
            $Devotee_Address_2="";
        }
        else {
            $Devotee_Address_2=htmlspecialchars(strip_tags($requestData['devotee_address_2']));
        }

        //Devotee Station
        if (empty($requestData['devotee_station'])){
            $Devotee_Station= "";
        }
        else{
            $Devotee_Station=htmlspecialchars(strip_tags($requestData['devotee_station']));
        }
        
        //Devotee State
        if (empty($requestData['devotee_state'])){
            $Devotee_State="";
        }
        else {
            $Devotee_State=htmlspecialchars(strip_tags($requestData['devotee_state']));
        }
        
        //Devotee Zip
        if (empty($requestData['devotee_zip'])){
            $Devotee_Zip="";
        }
        else {
            $Devotee_Zip=htmlspecialchars(strip_tags($requestData['devotee_zip']));
        }

        //Devotee Country
        if (empty($requestData['devotee_country'])){
            $Devotee_Country="";
        }
        else {
            $Devotee_Country=htmlspecialchars(strip_tags($requestData['devotee_country']));
        }

        /*if($this->debug){
            echo "email >> "; 
            var_dump(urldecode(strip_tags($requestData['devotee_email']))); 
            echo "result: "; 
            var_dump(filter_var(urldecode(strip_tags($requestData['devotee_email'])), FILTER_VALIDATE_EMAIL));             
        }*/
        //Devotee email address
        if (empty($requestData['devotee_email'])) {
            $Devotee_Email = "";
        } elseif (filter_var(urldecode(strip_tags($requestData['devotee_email'])), FILTER_VALIDATE_EMAIL)) {
            $Devotee_Email = urldecode(strip_tags($requestData['devotee_email']));
        } else {
            $Devotee_Email = "";
            $errormsg .= " Email Address is invalid.";
            $status = false;
        }

        //Devotee Cell Phone Number
        require_once dirname(__DIR__, 2) . '/includes/kdms_phone.php';
        if (empty($requestData['devotee_cell_phone_number'])) {
            $Devotee_Cell_Phone_Number = '';
        } else {
            [$phoneNorm, $phoneErr] = kdms_normalize_devotee_phone(
                (string) $requestData['devotee_cell_phone_number']
            );
            if ($phoneErr !== null) {
                $errormsg .= ' ' . $phoneErr;
                $status = false;
                $Devotee_Cell_Phone_Number = '';
            } else {
                $Devotee_Cell_Phone_Number = htmlspecialchars(strip_tags($phoneNorm));
            }
        }

        //Devotee Status
        if (empty($requestData['devotee_status'])){
            $Devotee_Status="A";
        }
        else {
            $Devotee_Status=htmlspecialchars(strip_tags($requestData['devotee_status']));
        }

        //Joined  Since
        if (empty($requestData['joined_since'])){
            $Joined_Since="";
        }
        else {
            $Joined_Since=htmlspecialchars(strip_tags($requestData['joined_since']));
        }

        //devotee Referral
        if (empty($requestData['devotee_referral'])){
            $Devotee_Referral="";
        }
        else {
            $Devotee_Referral=htmlspecialchars(strip_tags($requestData['devotee_referral']));
        }

        // Devotee Remarks
        if (empty($requestData['devotee_remarks'])){
            $Devotee_Remarks="";
        }
        else {
            $Devotee_Remarks=htmlspecialchars(strip_tags($requestData['devotee_remarks']));
        }
     
        //Comments
        if (empty($requestData['comments'])){
            $Comments="";
        }
        else {
            $Comments=htmlspecialchars(strip_tags($requestData['comments']));
        }

        //Devotee record updated by
        $Devotee_Record_Updated_By='Anil'; //to be fixed userid

        //Devotee Seva ID
        if (empty($requestData['devotee_seva_id'])){
            $Devotee_Seva_ID="UN";
        }
        else {
            $Devotee_Seva_ID=htmlspecialchars(strip_tags($requestData['devotee_seva_id']));
        }
        
        //Devotee Seva Status
        $Devotee_Seva_Status = "Assigned";

        //Devotee Accommodation ID
        if (empty($requestData['devotee_accommodation_id'])){
            $Devotee_Accommodation_ID="0";
        }
        else {
            $Devotee_Accommodation_ID=htmlspecialchars(strip_tags($requestData['devotee_accommodation_id']));
        }

        //Devotee Accommodation Status
        $Devotee_Accomodation_Status = "Allocated";
        
        //-- Not needed anymore since event functionality has been added
        //$Devotee_Accomodation_Year = date('y'); 
        //$Devotee_Seva_Year = date('y');
        // $now = date('Y-m-d H:i:s');

        // Event ID
        if (empty($requestData['eventId'])) {
            $errormsg .= " Event ID is missing.";
            $status = false;
        }
        else {
            $eventId = htmlspecialchars(strip_tags($requestData['eventId']));
        }
   
        if ($status == false) {
            $res['status'] = $status;
            $res['message'] = $errormsg;
            return $res;
        }

        // Devotee Key — reserve early on Add Devotee page; dedup before first INSERT (Phase 2).
        require_once dirname(__DIR__, 2) . '/includes/DeduplicationService.php';
        require_once dirname(__DIR__, 2) . '/includes/IdNormalizer.php';

        $query = "";
        $candidateKey = !empty($requestData['devotee_key'])
            ? strtoupper(trim((string) $requestData['devotee_key']))
            : strtoupper($this->generateId());
        $unique_id = $candidateKey;
        $rowExists = DeduplicationService::devoteeRowExists($this->conn, $candidateKey);
        $dedupUserMessage = '';

        [$saveIdType, $saveIdNumber] = IdNormalizer::resolveForDedup($Devotee_ID_Type, $Devotee_ID_Number);
        if ($saveIdType !== '') {
            $Devotee_ID_Type = $saveIdType;
        }
        if ($saveIdNumber !== '') {
            $Devotee_ID_Number = $saveIdNumber;
        }

        if (!$rowExists) {

            $dedupPayload = [
                'Devotee_Key' => $candidateKey,
                'Devotee_First_Name' => $Devotee_First_Name,
                'Devotee_Last_Name' => $Devotee_Last_Name,
                'Devotee_ID_Type' => $Devotee_ID_Type,
                'Devotee_ID_Number' => $Devotee_ID_Number,
                'Devotee_Cell_Phone_Number' => $Devotee_Cell_Phone_Number,
                'Devotee_DOB' => $Devotee_DOB === '1900-01-01' ? '' : $Devotee_DOB,
                'Devotee_Station' => $Devotee_Station,
                'Devotee_Type' => $Devotee_Type,
                'Devotee_Status' => $Devotee_Status,
                'eventId' => $eventId,
            ];

            try {
                $svc = new DeduplicationService($this->conn, $eventId, 'STAFF-UI');
                $dedup = $svc->applyDeduplication($dedupPayload, false);
                $unique_id = strtoupper((string) $dedup['devotee_key']);

                if ($dedup['action'] === 'merged' && strcasecmp($unique_id, $candidateKey) !== 0) {
                    $dedupUserMessage = 'Duplicate detected: this record was merged into existing devotee '
                        . $unique_id . ' (same ID number). Your ID scan was kept on the existing record.';
                } elseif ($dedup['action'] === 'merged') {
                    $dedupUserMessage = 'Duplicate detected: saved as existing devotee ' . $unique_id . '.';
                } elseif ($dedup['action'] === 'flagged_new') {
                    $dedupUserMessage = 'Possible duplicate on file — please verify before printing cards.';
                }
            } catch (Throwable $e) {
                $res['status'] = false;
                $res['message'] = 'Deduplication failed. Save aborted.';
                if ($this->debug) {
                    $res['info'] = $e->getMessage();
                }

                return $res;
            }
        }

        //Use replace function for insert as well as update thru stored procedure
        $query = "CALL PROC_REPLACE_DEVOTEE_W_SEVA_I(";
        $query = $query . "'" .
            $unique_id . "', '" . // `p_Devotee_Key` 
            $Devotee_Type . "', '" . // `p_Devotee_Type` 
            $Devotee_First_Name . "', '" . // `p_Devotee_First_Name` VARCHAR(50),
            $Devotee_Last_Name . "', '" . // `p_Devotee_Last_Name` VARCHAR(50),
            $Devotee_Gender . "', '" . // `p_Devotee_Gender` VARCHAR(6),
            $Devotee_DOB . "', '" . // `p_Devotee_DOB` DATE,
            $Devotee_ID_Type . "', '" . // `p_Devotee_ID_Type` VARCHAR(10),
            $Devotee_ID_Number . "', '" . // `p_Devotee_ID_Number` VARCHAR(50),
            $Devotee_Address_1 . "', '" . // `p_Devotee_Address_1` VARCHAR(100),
            $Devotee_Address_2 . "', '" . // `p_Devotee_Address_2` VARCHAR(100),
            $Devotee_Station . "', '" . // `p_Devotee_Station` VARCHAR(50),
            $Devotee_State . "', '" . // `p_Devotee_State` VARCHAR(25),
            $Devotee_Zip . "', '" . // `p_Devotee_Zip` VARCHAR(12),
            $Devotee_Country . "', '" . // `p_Devotee_Country` VARCHAR(20) ,
            $Devotee_Email . "', '" . // `p_Devotee_Email` VARCHAR(40) ,
            $Devotee_Cell_Phone_Number . "', '" . // `p_Devotee_Cell_Phone_Number` VARCHAR(15),
            $Devotee_Status . "', '" . // `p_Devotee_Status` VARCHAR(20),
            $Joined_Since . "', '" . // `p_Joined_Since`  VARCHAR(4),
            $Devotee_Referral . "', '" . // `p_Devotee_Referral` VARCHAR(50),
            $Devotee_Remarks . "', '" . // `p_Devotee_Remarks` VARCHAR(250),
            $Comments . "', '" . // `p_Comments`  VARCHAR(250),
            $Devotee_Record_Updated_By . "', '" . // `p_Devotee_Record_Updated_By`  VARCHAR(10),
            $Devotee_Seva_ID . "', '" . // `p_Devotee_Seva_Id` VARCHAR(6),
            $Devotee_Seva_Status . "', '" . // `p_Devotee_Seva_Status` VARCHAR(10),
            $Devotee_Accommodation_ID . "', '" . // `p_Devotee_Accommodation_ID` VARCHAR(10),
            $Devotee_Accomodation_Status . "', '" . // `p_Devotee_Accomodation_Status` VARCHAR(10),
            $eventId . "')" ; // `p_Event_ID` VARCHAR(10)
       // old code - to be deleted after testing of new code is complete
        /*
        $query = $query . "'" .
                $unique_id . "', '" . //:id,
                $Devotee_Type . "', '" . //:devotee_type,
                $Devotee_First_Name . "', '" . //:devotee_first_name,
                $Devotee_Last_Name . "', '" . //:devotee_last_name,
                $Devotee_Gender . "', '" . //:devotee_gender,
                $Devotee_ID_Type . "', '" . //:devotee_id_type,
                $Devotee_ID_Number . "', '" . //:devotee_id_number,
                $Devotee_Station . "', '" . //:devotee_station,
                $Devotee_Cell_Phone_Number . "', '" . //:devotee_cell_phone_number,
                $Devotee_Status . "', '" . //:devotee_status,
                $Devotee_Remarks . "', '" . //:devotee_remarks,
                $Devotee_Referral . "', '" . //:devotee_referral,
                $Devotee_Seva_ID . "', '" . //:devotee_seva,
                "Assigned" . "', '" . //:devotee_seva_status,
                $Devotee_Record_Updated_By . "', '" . //:devotee_record_updated_by,
                $Devotee_Accommodation_ID . "', '" . //:devotee_accommodation_id,
                $Devotee_Accomodation_Status . "','" . //:devotee_accommodation_status)" ;
                $Devotee_Address_1 . "', '" . 
                $Devotee_Address_2 . "', '" . 
                $Devotee_State . "', '" . 
                $Devotee_Zip . "', '" . 
                $Devotee_Country . "', '" . 
                $Comments . "', '" .
                $Joined_Since . "' , '".
                $eventId . "')" ;
           
         if($this->debug){
             echo $query; 
         }
        */    

        // prepare query
        $stmt = $this->conn->prepare($query);

        if($this->debug){echo "reaching before statement execution: "; var_dump($stmt);}

        try {
            if ($stmt->execute()) {
                $res['status'] = true;
                $res['message'] = $dedupUserMessage;
                $res['info'] = $unique_id;
            } else {
                $res['status'] = false;
                $res['message'] = '[Devotees] Adding Devotee Record Failed at API!!';
                if ($this->debug) {
                    $res['info'] = $query;
                } else {
                    $res['info'] = $stmt->errorInfo();
                }
            }
        } catch (PDOException $e) {
            $res['status'] = false;
            $res['message'] = $this->upsertDevoteeSaveErrorMessage($e);
            if ($this->debug) {
                $res['info'] = $e->getMessage();
            } else {
                $res['info'] = $stmt->errorInfo();
            }
            kdms_log('ERROR', 'upsertDevotee PROC_REPLACE failed', [
                'devotee_key' => $unique_id ?? '',
                'error' => $e->getMessage(),
            ]);
        }
        return $res;
         
    }

    private function upsertDevoteeSaveErrorMessage(PDOException $e): string
    {
        $msg = $e->getMessage();
        if (str_contains($msg, '1146') || stripos($msg, "doesn't exist") !== false) {
            return 'Save failed: database procedure is out of date (missing table). '
                . 'An operator must apply PROC_REPLACE_DEVOTEE_W_SEVA_I.sql on production.';
        }
        if (str_contains($msg, '1406') || stripos($msg, 'Data too long') !== false) {
            return 'Save failed. One or more fields exceed the maximum allowed length.';
        }
        if (str_contains($msg, '22001') || str_contains($msg, '22007') || stripos($msg, 'Incorrect date') !== false) {
            return 'Save failed. Check phone number (max 10 digits), dates, and other field formats.';
        }

        return 'Save failed. Check phone number (max 10 digits), dates, and other field lengths.';
    }

    public function manageCardPrinting($requestData)
    {
        $res = array(
            'status' => false,
            'message' => '',
            'info' => '',
        );
        $Print_Record_Updated_By = 'Anil';

        if (empty($requestData['devotee_key'])) {
            $res['message'] = ' Devotee Key is missing.';
            return $res;
        }

        $normalizedKeys = $this->normalizeDevoteeKeysFromRequest((string) $requestData['devotee_key']);
        if (empty($normalizedKeys)) {
            $res['message'] = ' Invalid or empty devotee key(s).';
            return $res;
        }

        $byUserQuoted = $this->conn->quote($Print_Record_Updated_By);
        $inClause = implode(',', array_map(function ($k) {
            return $this->conn->quote($k);
        }, $normalizedKeys));

        $query = [];
        $rq = $requestData['requestType'] ?? '';

        if ($rq === 'addToPrintQueue') {
            foreach ($normalizedKeys as $k) {
                $qk = $this->conn->quote($k);
                $query[] = 'REPLACE INTO `card_print_log`(
                    `Devotee_Key`,
                    `Print_Status`,
                    `Print_Requested_Date_Time`,
                    `Print_Requested_By_User`
                ) VALUES(' . $qk . ", 'A', NOW(), {$byUserQuoted})";
            }
        } elseif ($rq === 'removeFromPrintQueue') {
            $query[] = 'REPLACE INTO `card_print_archive` SELECT * FROM `card_print_log` WHERE `Devotee_Key` IN (' . $inClause . ')';
            $query[] = 'DELETE FROM `card_print_log` WHERE `Devotee_Key` IN (' . $inClause . ')';
            // card_print_archive only — print_log is append-only (see PrintLog.php).
            $query[] = 'DELETE FROM `card_print_archive`
                WHERE `Devotee_Key` NOT IN (
                    SELECT `Devotee_Key` FROM (
                        SELECT `Devotee_Key` FROM `card_print_archive` ORDER BY `Print_Requested_Date_Time` LIMIT 25
                    ) AS tmp
                )';
        } else {
            $res['message'] = ' Invalid request type for card printing.';
            return $res;
        }

        if ($this->debug) {
            echo isset($requestData['eventId']) ? (string) $requestData['eventId'] : '';
            var_dump($requestData);
            var_dump($query);
        }

        $res['status'] = true;
        $res['message'] = '';
        $res['info'] = implode(',', $normalizedKeys);

        foreach ($query as $sql) {
            $stmt = $this->conn->prepare($sql);
            if ($this->debug) {
                var_dump($stmt);
            }
            if (!$stmt->execute()) {
                $res['status'] = false;
                $res['message'] = '[Card Print] Adding/Removing Devotee Card to/from print queue failed.';
                $res['info'] = $stmt->errorInfo();
                break;
            }
        }

        if ($res['status'] && $rq === 'removeFromPrintQueue' && !empty($requestData['eventId'])) {
            $eventId = preg_replace('/[^\w.-]/', '', trim(strip_tags((string) $requestData['eventId']))) ?? '';
            if ($eventId !== '') {
                PrintLog::recordManyIfNotExistsToday(
                    $this->conn,
                    $normalizedKeys,
                    $eventId,
                    $Print_Record_Updated_By
                );
            }
        }

        return $res;
    }

    public function deleteDevoteeRecord($requestData)
    {
        $res = array();
        $res['status'] = false;
        $res['message'] = '';
        $res['info'] = '';
        $errormsg = "Error occured";
        $status = true;
        $query = array();   
        $Devotee_Key = '';
        $eventId = ''; 
        $registrationOnly = false;

        if (empty($requestData['devotee_key'])) {
            $errormsg .= " Devotee Key is missing.";
            $status = false;
        } else {
            $Devotee_Key = htmlspecialchars(strip_tags($requestData['devotee_key']));
        }

        // Event ID
        if (empty($requestData['eventId'])) {
            $errormsg .= " Event ID is missing.";
            $status = false;
        }
        else {
            $eventId = htmlspecialchars(strip_tags($requestData['eventId']));
        }
   
        // Only delete registration, not the full devotee record?
        if (!empty($requestData['registrationOnly'])) { 
            $registrationOnly = htmlspecialchars(strip_tags($requestData['registrationOnly']));
        }

        if ($status == false) {
            $res['status'] = $status;
            $res['message'] = $errormsg;
            return $res;
        }

        if($this->debug){var_dump( $requestData);}
        // If complete devotee information has to be deleted
        if(!$registrationOnly){
            $query[0] = "delete from devotee_accomodation where Devotee_Key = '" . $Devotee_Key . "'";
            $query[1] = "delete from devotee where Devotee_Key = '" . $Devotee_Key . "'";
            $query[2] = "delete from devotee_attendance where Devotee_Key = '" . $Devotee_Key . "'";
            $query[3] = "delete from devotee_amenities_allocation where Devotee_Key = '" . $Devotee_Key . "'";
            $query[4] = "delete from devotee_demographics where Devotee_Key = '" . $Devotee_Key . "'";
            $query[5] = "delete from devotee_id where Devotee_Key = '" . $Devotee_Key . "'";
            $query[6] = "delete from devotee_photo where Devotee_Key = '" . $Devotee_Key . "'";
            $query[7] = "delete from devotee_remarks where Devotee_Key = '" . $Devotee_Key . "'";
            $query[8] = "delete from devotee_seva where Devotee_Key = '" . $Devotee_Key . "'";
            $query[9] = "delete from office_duty where Devotee_Key = '" . $Devotee_Key . "'";
            $query[10] = "delete from office_duty_archive where Devotee_Key = '" . $Devotee_Key . "'";
            // print_log is append-only for kitchen counts — never delete on devotee removal
            $query[11] = "CALL `PROC_REFRESH_ACCO_COUNT_W_EVENT`('" . $eventId . "')";
            $query[12] = "CALL `PROC_REFRESH_AMENITIES_COUNT`('" . $eventId . "')";
            $query[13] = "CALL `PROC_REFRESH_SEVA_COUNT_I`('" . $eventId . "');";
        }
        // Otherwise its unregistering records, which means, delete omly selected records from the current event 
        else {
            $query[0] = "delete from devotee_accomodation where Devotee_Key = '" . $Devotee_Key . "' and accommodation_event = '" . $eventId . "'" ; 
            $query[1] = "delete from devotee_attendance where Devotee_Key = '" . $Devotee_Key . "' and seva_event = '" . $eventId . "'";
            $query[2] = "delete from devotee_amenities_allocation where Devotee_Key = '" . $Devotee_Key . "' and allocation_event = '" . $eventId . "'";
            $query[3] = "delete from devotee_seva where Devotee_Key = '" . $Devotee_Key . "' and seva_event = '" . $eventId . "'";
            $query[4] = "delete from office_duty where Devotee_Key = '" . $Devotee_Key . "' and duty_event = '" . $eventId . "'";
            $query[5] = "delete from office_duty_archive where Devotee_Key = '" . $Devotee_Key . "' and duty_event = '" . $eventId . "'";  
            //refresh the accommodation, amenities and seva counts in both cases
            $query[6] = "CALL `PROC_REFRESH_ACCO_COUNT_W_EVENT`('" . $eventId . "')";
            $query[7] = "CALL `PROC_REFRESH_AMENITIES_COUNT`('" . $eventId . "')";
            $query[8] = "CALL `PROC_REFRESH_SEVA_COUNT_I`('" . $eventId . "');";
        }

        
        $res['status'] = true;
        $res['message'] = "";
        $res['info'] = $Devotee_Key;
        for ($i = 0; $i < sizeof($query); $i++) {
            $stmt = $this->conn->prepare($query[$i]);
            if($this->debug){var_dump($stmt);}
            if (!$stmt->execute()) {
                $res['status'] = false;
                $res['message'] = "Unable to delete devotee record. Query: " . $query[$i];
                $res['info'] = $stmt;
            }
        }
        return $res;
    }

    public function registerExistingDevotee($requestData) {
        $res = array();
        $res['status'] = false;
        $res['message'] = '';
        $res['info']='';
        $errormsg = "";
        $status = true;


        //Devotee Key
        if (empty($requestData['devotee_key'])) {
            $errormsg .= "Devotee Key is missing.";
            $status = false;
        }
        else{
            $Devotee_Key=htmlspecialchars(strip_tags($requestData['devotee_key']));
        }

        //Devotee Seva ID
        if (empty($requestData['devotee_seva_id'])){
            $Devotee_Seva_ID="UN";
        }
        else {
            $Devotee_Seva_ID=htmlspecialchars(strip_tags($requestData['devotee_seva_id']));
        }
        
        //Devotee Seva Status
        $Devotee_Seva_Status = "Assigned";

        //Devotee Accommodation ID
        if (empty($requestData['devotee_accommodation_id'])){
            $Devotee_Accommodation_ID="0";
        }
        else {
            $Devotee_Accommodation_ID=htmlspecialchars(strip_tags($requestData['devotee_accommodation_id']));
        }

        //Devotee Accommodation Status
        $Devotee_Accomodation_Status = "Allocated";
        
        // Event ID
        if (empty($requestData['eventId'])) {
            $errormsg .= " Event ID is missing.";
            $status = false;
        }
        else {
            $eventId = htmlspecialchars(strip_tags($requestData['eventId']));
        }
   
        if ($status == false) {
            $res['status'] = $status;
            $res['message'] = $errormsg;
            return $res;
        }

        $devotee_record_updated_by = 'Anil'; //to be fixed userid
        //Use replace function for insert as well as update thru stored procedure
        $query = "CALL PROC_REGISTER_EXISTING_DEVOTEE_FOR_EVENT(";
        $query = $query . "'" .
            $Devotee_Key . "', '" . // `p_Devotee_Key` 
            $Devotee_Seva_ID . "', '" . // `p_Devotee_Seva_Id` VARCHAR(6),
            $Devotee_Seva_Status . "', '" . // `p_Devotee_Seva_Status` VARCHAR(10),
            $Devotee_Accommodation_ID . "', '" . // `p_Devotee_Accommodation_ID` VARCHAR(10),
            $Devotee_Accomodation_Status . "', '" . // `p_Devotee_Accomodation_Status` VARCHAR(10),
            $devotee_record_updated_by . "', '" . // `p_devotee_record_updated_by` VARCHAR(10),
            $eventId . "')" ; // `p_Event_ID` VARCHAR(10)
       
        // prepare query
        $stmt = $this->conn->prepare($query);

        if($this->debug){echo "reaching before statement execution: "; var_dump($stmt);}

        if ($stmt->execute()) {
            $res['status'] = true;
            $res['message'] = '';
            $res['info'] = $Devotee_Key;
        } else {
            $res['status'] = false;
            $res['message'] = "[Devotees] Registering Devotee Record Failed at API!!";
            if ($this->debug) {
                $res['info'] = $query;
            } else {
                $res['info'] = $stmt->errorInfo();
            }
        }
        return $res;
         
    }

    public function old_deleteDevoteeRecord($requestData)
    {
        $res = array();
        $res['status'] = false;
        $res['message'] = '';
        $res['info'] = '';
        $errormsg = "Error occured";
        $status = true;
        $query = array();   
        $Devotee_Key = '';
        $eventId = ''; 

        if (empty($requestData['devotee_key'])) {
            $errormsg .= " Devotee Key is missing.";
            $status = false;
        } else {
            $Devotee_Key = htmlspecialchars(strip_tags($requestData['devotee_key']));
        }

        // Event ID
        if (empty($requestData['eventId'])) {
            $errormsg .= " Event ID is missing.";
            $status = false;
        }
        else {
            $eventId = htmlspecialchars(strip_tags($requestData['eventId']));
        }

        if ($status == false) {
            $res['status'] = $status;
            $res['message'] = $errormsg;
            return $res;
        }

        if($this->debug){echo $requestData['eventId']; die;}
        
        $query[0] = "delete from devotee_accomodation where Devotee_Key = '" . $Devotee_Key . "'";
        $query[1] = "delete from devotee where Devotee_Key = '" . $Devotee_Key . "'";
        $query[2] = "delete from devotee_attendance where Devotee_Key = '" . $Devotee_Key . "'";
        $query[3] = "delete from devotee_amenities_allocation where Devotee_Key = '" . $Devotee_Key . "'";
        $query[4] = "delete from devotee_demographics where Devotee_Key = '" . $Devotee_Key . "'";
        $query[5] = "delete from devotee_id where Devotee_Key = '" . $Devotee_Key . "'";
        $query[6] = "delete from devotee_photo where Devotee_Key = '" . $Devotee_Key . "'";
        $query[7] = "delete from devotee_remarks where Devotee_Key = '" . $Devotee_Key . "'";
        $query[8] = "delete from devotee_seva where Devotee_Key = '" . $Devotee_Key . "'";
        $query[9] = "delete from office_duty where Devotee_Key = '" . $Devotee_Key . "'";
        $query[10] = "delete from office_duty_archive where Devotee_Key = '" . $Devotee_Key . "'";
        // print_log is append-only for kitchen counts — never delete on devotee removal
        $query[11] = "CALL `PROC_REFRESH_ACCO_COUNT_W_EVENT`('" . $eventId . "')";
        $query[12] = "CALL `PROC_REFRESH_AMENITIES_COUNT`('" . $eventId . "')";
        $query[13] = "CALL `PROC_REFRESH_SEVA_COUNT_I`('" . $eventId . "');";

        $res['status'] = true;
        $res['message'] = "";
        $res['info'] = $Devotee_Key;
        for ($i = 0; $i < sizeof($query); $i++) {
            $stmt = $this->conn->prepare($query[$i]);
            if($this->debug){var_dump($stmt);}
            if (!$stmt->execute()) {
                $res['status'] = false;
                $res['message'] = "Unable to delete devotee record. Query: " . $query[$i];
                $res['info'] = $stmt;
            }
        }
        return $res;
    }

        public function OLD_manageCardPrinting($requestData)
        {
            $res = array();
            $res['status'] = false;
            $res['message'] = '';
            $res['info'] = '';
            $errormsg = "Error occured";
            $status = true;
            $query = "";
            $Print_Record_Updated_By = 'Anil'; //to be fixed userid
            $now = date('Y-m-d H:i:s');
    
            if (empty($requestData['devotee_key'])) {
                $errormsg .= " Devotee Key is missing.";
                $status = false;
            } else {
                $Devotee_Key = htmlspecialchars(strip_tags($requestData['devotee_key']));
            }
    
            if ($requestData['requestType'] == "addToPrintQueue") {
                $query = "REPLACE INTO `card_print_log`(
                        `Devotee_Key`,
                        `Print_Status`,
                        `Print_Requested_Date_Time`,
                        `Print_Requested_By_User`
                    )
                    VALUES('" . $Devotee_Key . "','A', NOW(), '" . $Print_Record_Updated_By . "')";
    
            } else {
                //$query = "UPDATE `card_print_log` SET Print_Status = 'C', Print_Completion_Date_Time = NOW() WHERE `Devotee_Key` in (" . $Devotee_Key . ")";
                $query = "REPLACE INTO `card_print_archive`(
                    `Devotee_Key`,
                    `Print_Status`,
                    `Print_Requested_Date_Time`,
                    `Print_Requested_By_User`
                )
                VALUES('" . $Devotee_Key . "','A', NOW(), '" . $Print_Record_Updated_By . "')";
            }
    
            $stmt = $this->conn->prepare($query);
    
            
            if ($stmt->execute()) {
                $res['status'] = true;
                $res['message'] = "";
                $res['info'] = $Devotee_Key;
                if ($requestData['requestType'] == "removeFromPrintQueue") {
                    $query1 = "DELETE from `card_print_log` WHERE `Devotee_Key` in (" . $Devotee_Key . ")";
                    $stmt1 = $this->conn->prepare($query1);
                    if($this->debug){echo "reaching in the remove card print function"; var_dump($requestData); var_dump($stmt1);}
                    if (!$stmt1->execute()) {
                        $res['status'] = false;
                        $res['message'] = "[Card Print] Removing Devotee Card from print queue failed at API!! Error Info: " . $query1;
                        $res['info'] = $Devotee_Key;
                    }
                }
                } else {
                    $res['status'] = false;
                    $res['message'] = "[Card Print] Adding/Removing Devotee Card to/from print queue failed at API!! Error INfo: " . $query;
                    $res['info'] = $stmt;
                }
                return $res;
            }
    public function manageAmenityAllocation($requestData) {
        $res = array();
        $res['status'] = TRUE;
        $res['message'] = '';
        $res['info']='';
        $errormsg = "Error occured";
        $status = true;
        $query = "";
        $Amenity_Managed_By='Anil'; //to be fixed userid
        $now = date('Y-m-d H:i:s');
        
        $Devotee_Key="";
        $Amenity_Keys="";
        $Amenity_Quantities="";
        $Amenity_Key = array();
        $Amenity_Quantity = array();
        $eventId = "";
        
        if (empty($requestData['devotee_key'])) {
            $errormsg .= " Devotee Key is missing.";
            $status = false;
        }
        else{
            $Devotee_Key=htmlspecialchars(strip_tags($requestData['devotee_key']));
        }
        
        if (empty($requestData['amenity_key'])) {
            $errormsg .= " Amenity Key is missing.";
            $status = false;
        }
        else{
            $Amenity_Keys=htmlspecialchars(strip_tags($requestData['amenity_key']));
        }
        
        if (empty($requestData['amenity_quantity'])) {
            $errormsg .= " Amenity quantity is missing.";
            $status = false;
        }
        else{
            $Amenity_Quantities=htmlspecialchars(strip_tags($requestData['amenity_quantity']));
        }
        
        if (empty($requestData['eventId'])) {
            $errormsg .= " Event ID is missing.";
            $status = false;
        }
        else{
            $eventId=htmlspecialchars(strip_tags($requestData['eventId']));
        }

        $Amenity_Key = explode(",", $Amenity_Keys);
        $Amenity_Quantity = explode(",", $Amenity_Quantities);
        
        foreach ($Amenity_Key as $key => $Amenity_Key_Value) {
            if(!empty($Amenity_Key[$key]) && !empty($Amenity_Quantity[$key])){
                $query = "CALL `PROC_MANAGE_AMENITY`( '" .
                    $Devotee_Key . "','" .
                    $Amenity_Key[$key] . "','" .
                    $eventId . "'," .
                    $Amenity_Quantity[$key] . ",'" .
                    $Amenity_Managed_By . "')";
                
                $stmt = $this->conn->prepare($query);
                if ($stmt->execute()) {                    
                    $res['info'] = $res['info'] . " Devotee_Key: " . $Devotee_Key . ", EventId: " . $eventId . ", Amenity_Key: " . $Amenity_Key[$key] . " processed!" ;
                }
                else{
                    $res['status'] = false;
                    $res['message'] = "[Amenity Management] Adding/Removing Devotee Amenity failed at API!! Error Info: " . $query;
                    $res['info'] = $res['info'] . " Devotee_Key: " . $Devotee_Key . ", EventId: " . $eventId . ", Amenity_Key: " . $Amenity_Key[$key] . " failed to process!" ;
                }
            }            
        }        
        return $res;
    }
    
    public function generateId() {
        $result = ['1'];
        // $id="KDHM15562AF1ACE";
        while (!empty($result)) {
            //$id = 'KDHM' . rand(0, 9999) . substr(md5(rand()), 0, 7);
            //$id = 'P' . date('y') . date('m') . date('d') . rand(0, 9999) . substr(md5(rand()), 0, 7);
            $id = 'P' . date('y') . date('m') . date('d') . rand(0, 999) ;
            $quoted = $this->conn->quote(strtoupper($id));
            $sql = 'SELECT 1 FROM devotee WHERE Devotee_Key = ' . $quoted
                . ' UNION SELECT 1 FROM devotee_id WHERE Devotee_Key = ' . $quoted
                . ' UNION SELECT 1 FROM devotee_photo WHERE Devotee_Key = ' . $quoted
                . ' LIMIT 1';
            $result = [];
            foreach ($this->conn->query($sql) as $row) {
                if (!empty($row)) {
                    $result[] = $row;
                }
            }
        }
        return strtoupper($id);
    }

    public function validateDate($date, $format = 'Y-m-d'){
        $d = DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }

    public function upsertDevoteeRemark($requestData)
    {
        $res = array();
        $res['status'] = false;
        $res['message'] = '';
        $res['info'] = '';
        $errormsg = "";
        $status = true;

        $query = "";
        $devoteeKey = "";
        $remarkType = "MISC";
        $remarkEvent = "";
        $rating = "0";
        $remark = "";
        $remarkUpdateDateTime = date('Y-m-d H:i:s');
        $remarkUpatedBy = "Unknown";


        if (empty($requestData['eventId'])) {
            $errormsg .= " Event ID is missing.";
            $status = false;
        } else {
            $remarkEvent = htmlspecialchars(strip_tags($requestData['eventId']));
        }

        if (empty($requestData['devotee_key'])) {
            $errormsg .= " Devotee Key is missing.";
            $status = false;
        } else {
            $devoteeKey = htmlspecialchars(strip_tags($requestData['devotee_key']));
        }

        if (!empty($requestData['remark_type'])) {
            $remarkType = htmlspecialchars(strip_tags($requestData['remark_type']));
        }

        if (!empty($requestData['rating'])) {
            $rating = htmlspecialchars(strip_tags($requestData['rating']));
        }

        if (!empty($requestData['remark'])) {
            $remark = htmlspecialchars(strip_tags($requestData['remark']));
        }

        if (!empty($requestData['userId'])) {
            $remarkUpatedBy = htmlspecialchars(strip_tags($requestData['userId']));
        }

        if ($this->debug) {
            echo "reaching here..";
            echo $status, " ", $errormsg; 
        }

        if ($status == false) {
            $res['status'] = $status;
            $res['message'] = $errormsg;
            return $res;
        }

        $queryCheck = "SELECT rating, remark FROM devotee_remarks WHERE devotee_key = '" . $devoteeKey . "' AND remark_type = '" . $remarkType . "' AND remark_event = '" . $remarkEvent . "'";


        if ($this->debug) {
            echo "reaching here..";
            var_dump($queryCheck); 
        }

        $results = $this->conn->query($queryCheck);

        if (!empty($row = $results->fetchObject())) {
            if($row->{'rating'} <> $rating) {
            $rating = ($rating + $row->{'rating'}) / 2;
            }

            $tempAr = explode(' || ', $row->{'remark'});
            $lastRem = $tempAr[sizeof($tempAr) - 1];

            if($this->debug){echo "\n from UpsertDevoteeRemark: \n  Last remark: ", $lastRem, "\n current Remark: ", trim("[" . $remarkUpatedBy . "] " . $remark , "\n \n <<") ;}
            if(trim($lastRem) <> trim("[" . $remarkUpatedBy . "] " . $remark)){
                $remark = $row->{'remark'} . " || [" . $remarkUpatedBy . "] " . $remark;
            }
            else{
                $remark = $row->{'remark'} ;
            }
            
        }
        else {
            $remark = "[" . $remarkUpatedBy . "] " . $remark;
        }



        $query = "REPLACE INTO devotee_remarks (devotee_key, remark_type, remark_event, rating, remark, remark_update_date_time, remark_updated_by) 
                 VALUES (   '" . $devoteeKey . "' , 
                    '" . $remarkType . "' , 
                    '" . $remarkEvent . "' , 
                    " . $rating . ", 
                    '" . $remark . "',
                    NOW(), 
                    '" . $remarkUpatedBy . "' )";

        if ($this->debug) {
           echo "\n >>";
            var_dump($query);
        }
        // prepare query
        $stmt = $this->conn->prepare($query);

        if ($stmt->execute()) {
            //var_dump($stmt);
            $res['status'] = true;
            $res['message'] = "";
            $res['info'] = $devoteeKey;
        } else {
            $res['status'] = false;
            $res['message'] = "[Remark] Upserting Remarks Failed at API!!";
            $res['info'] = $stmt;
        }
        return $res;

    }

    public function upsertDevoteeAttendance($requestData)
    {
        $res = array();
        $res['status'] = false;
        $res['message'] = '';
        $res['info'] = '';
        $errormsg = "";
        $status = true;

        $query = "";
        $devoteeKey = "";
        $sevaEvent = "";
        $sevaId = "UN";
        $attendanceDate = date('Y-m-d');
        $rating = "1";
        $remark = "";
        //$attendanceUpdateDateTime = date('Y-m-d H:i:s');
        $attendanceUpatedBy = "Unknown";

        if (empty($requestData['eventId'])) {
            $errormsg .= " Event ID is missing.";
            $status = false;
        } else {
            $sevaEvent = htmlspecialchars(strip_tags($requestData['eventId']));
        }

        if (empty($requestData['seva_id'])) {
            $errormsg .= " Seva ID is missing.";
            $status = false;
        } else {
            $sevaId = htmlspecialchars(strip_tags($requestData['seva_id']));
        }

        if (empty($requestData['devotee_key'])) {
            $errormsg .= " Devotee Key is missing.";
            $status = false;
        } else {
            $devoteeKey = htmlspecialchars(strip_tags($requestData['devotee_key']));
        }

        if (!empty($requestData['attendance_date'])) {
            $attendanceDate = htmlspecialchars(strip_tags($requestData['attendance_date']));
        }

        if (!empty($requestData['rating'])) {
            $rating = htmlspecialchars(strip_tags($requestData['rating']));
        }

        if (!empty($requestData['remark'])) {
            $remark = htmlspecialchars(strip_tags($requestData['remark']));
        }

        if (!empty($requestData['userId'])) {
            $attendanceUpatedBy = htmlspecialchars(strip_tags($requestData['userId']));
        }

        if ($this->debug) {
            echo "reaching here..";
            echo $status, " ", $errormsg; 
        }

        if ($status == false) {
            $res['status'] = $status;
            $res['message'] = $errormsg;
            return $res;
        }
/*
        $queryCheck = "SELECT rating, remark FROM devotee_remarks WHERE devotee_key = '" . $devoteeKey . "' AND remark_type = '" . $remarkType . "' AND remark_event = '" . $remarkEvent . "'";


        if ($this->debug) {
            echo "reaching here..";
            var_dump($queryCheck); 
        }

        $results = $this->conn->query($queryCheck);

        if (!empty($row = $results->fetchObject())) {
            if($row->{'rating'} <> $rating) {
            $rating = ($rating + $row->{'rating'}) / 2;
            }

            $tempAr = explode(' || ', $row->{'remark'});
            $lastRem = $tempAr[sizeof($tempAr) - 1];

            if($this->debug){echo "\n from UpsertDevoteeRemark: \n  Last remark: ", $lastRem, "\n current Remark: ", trim("[" . $remarkUpatedBy . "] " . $remark , "\n \n <<") ;}
            if(trim($lastRem) <> trim("[" . $remarkUpatedBy . "] " . $remark)){
                $remark = $row->{'remark'} . " || [" . $remarkUpatedBy . "] " . $remark;
            }
            else{
                $remark = $row->{'remark'} ;
            }
            
        }
        else {
            $remark = "[" . $remarkUpatedBy . "] " . $remark;
        }

*/

        $query = "REPLACE INTO devotee_attendance (devotee_key, seva_id, seva_event, attendance_date, rating, remark, attendance_update_date_time, attendance_updated_by) 
                 VALUES (   '" . $devoteeKey . "' , 
                    '" . $sevaId . "' , 
                    '" . $sevaEvent . "' , 
                    '" . $attendanceDate . "' , 
                    " . $rating . ", 
                    '" . $remark . "',
                    NOW(), 
                     '" . $attendanceUpatedBy . "' )";

        if ($this->debug) {
           echo "\n >>";
            var_dump($query);
        }
        // prepare query
        $stmt = $this->conn->prepare($query);

        if ($stmt->execute()) {
            //var_dump($stmt);
            $res['status'] = true;
            $res['message'] = "";
            $res['info'] = $devoteeKey;
        } else {
            $res['status'] = false;
            $res['message'] = "[Attendance] Upserting Attendance Failed at API!!";
            $res['info'] = $stmt;
        }
        return $res;

    }
}
?>
