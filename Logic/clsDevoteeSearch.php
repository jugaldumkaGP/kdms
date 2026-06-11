<?php

require_once dirname(__DIR__) . '/includes/kdms_internal_http.php';

class clsDevoteeSearch {

    private $url;
    //$url ="http://localhost/KDMS/api/searchDevotee.php";
    private $request = array();
private $debug = false;
    //put your code here

    public function __construct($requestObject) {
        $this->request = $requestObject;
        
        // Include new config file in each page ,where we need data from configuration
        $config_data = include("../site_config.php");
        $this->url = $config_data['api_dir_server'] . 'searchDevotee.php';
    }

    public function getDevoteeDetails($eventId) {

        $response = $this->get_records_from_API($this->request['devotee_key'], "KEY", $eventId);

        return $response;
    }

    public function getDevoteeAmenities($eventId="") {

        $response = $this->get_records_from_API($this->request['devotee_key'], "DAD", $eventId);                
        return $response;
        
    }

    public function getParticipationRecord() {

        $response = $this->get_records_from_API($this->request['devotee_key'], "DPRO");
        return $response;

    }
    public function getParticipationRecords() {

        $response = $this->get_records_from_API($this->request['devotee_key'], "DPR");
        return $response;

    }

    public function getDevoteeRecords($eventId) {
        $response = "";
        if (!empty($this->request['mode']) AND ! empty($this->request['key'])) {
            $response = $this->get_records_from_API($this->request['key'], $this->request['mode'], $eventId);
        }
        return $response;
    }

    public function dynamicSearchDevotees() {
        $response = "";
        if (!empty($this->request['key'])) {
            $response = $this->get_records_from_API($this->request['key'], "DYN");
        }
        return $response;
    }

    private function get_records_from_API($requestData, $mode, $eventId = "") {

        kdms_begin_internal_apache_curl();
        $ch = curl_init();
        $url = $this->url . "?key=" . urlencode($requestData) . "&mode=" . $mode . "&eventId=" . $eventId;
        if (in_array($mode, ['SET', 'CUS'], true)) {
            $url .= '&include_photos=0';
        }
        if ($mode === 'SET' && !empty($this->request['filter'])) {
            $url .= '&filter=' . urlencode((string) $this->request['filter']);
        }
        if($this->debug){return  $url; die;}
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch,CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        kdms_curl_setopt_internal_cookie($ch);
        $response = curl_exec($ch);
       
//        return $response;
        try{
            if ($mode != 'DYN') {
                $response = json_decode($response, true);
                
            }
        }
        catch (PDOException $e) {
                        echo  $e->getMessage();
                        die;
                    }
        curl_close($ch);
        kdms_end_internal_apache_curl();
        return $response;
    }

}
