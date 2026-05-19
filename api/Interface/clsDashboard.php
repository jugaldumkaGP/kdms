<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of clsOptions
 *
 * @author jugaldumka
 */
class clsDashboard {

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
        // Get eventId from request or use empty string to get current year's event
        $eventId = !empty($requestData['eventId']) ? $requestData['eventId'] : "";
        
        // Get the query from getDevoteeCounts method
        $query = $this->getDevoteeCounts($eventId);
        
        // Debug flag to print query (if needed)
        if ($this->debug) {
            $res['query'] = $query;
        }
        
        // Execute the query
        $devoteeResults = array();
        $result = $this->conn->query($query);
        if (!empty($row = $result->fetchObject())) {
            $devoteeResults[0] = $row;
            if($this->debug){var_dump($row);}
        }
        
        return $devoteeResults;
    }

    private function getDevoteeCounts($eventId) {
        // If no eventId is provided, we attempt to get the current year's event
        if (empty($eventId)) {
            // Default to current year's Jun-Jul event format (e.g. '2025JB')
            $currentYear = date('Y');
            $eventId = $currentYear . 'JB';
        }
        // Comprehensive dashboard query that returns all metrics in a single query
        $query = "SELECT
        '$eventId' AS Event_ID,
        (
        (
            SELECT
            COUNT(DISTINCT d.Devotee_Key)
            FROM
            devotee d
            JOIN devotee_accomodation da ON d.Devotee_Key = da.Devotee_Key
            JOIN accommodation_master am ON da.Accomodation_Key = am.Accomodation_Key
            WHERE
            da.Accommodation_Event = '$eventId'
        ) + IFNULL(
            (
            SELECT
                COUNT(*)
            FROM
                temporary_registration
            WHERE
                event_id = '$eventId'
            ),
            0
        )
        ) AS Total_Registration_Count,
        (
        SELECT
            COUNT(DISTINCT d.Devotee_Key)
        FROM
            devotee d
            JOIN devotee_accomodation da ON d.Devotee_Key = da.Devotee_Key
            JOIN accommodation_master am ON da.Accomodation_Key = am.Accomodation_Key
        WHERE
            da.Accommodation_Event = '$eventId'
            AND am.Accomodation_Name NOT IN ('Own+Arrangement+%28Outside%29', 'Local')
            AND da.Accomodation_Status = 'Allocated'
        ) AS Ashram_Residents_Count,
        (
        (
            SELECT
            COUNT(DISTINCT d.Devotee_Key)
            FROM
            devotee d
            JOIN devotee_accomodation da ON d.Devotee_Key = da.Devotee_Key
            JOIN accommodation_master am ON da.Accomodation_Key = am.Accomodation_Key
            WHERE
            da.Accommodation_Event = '$eventId'
            AND d.Devotee_Status = 'D'
            AND d.Devotee_Type = 'T'
            AND am.Accomodation_Name = 'Other'
        ) + IFNULL(
            (
            SELECT
                COUNT(*)
            FROM
                temporary_registration
            WHERE
                event_id = '$eventId'
            ),
            0
        )
        ) AS Temporary_Day_Visitors_Count,
        (
       ( SELECT
            COUNT(DISTINCT d.Devotee_Key)
        FROM
            devotee d
            JOIN devotee_accomodation da ON d.Devotee_Key = da.Devotee_Key
            JOIN accommodation_master am ON da.Accomodation_Key = am.Accomodation_Key
        WHERE
            da.Accommodation_Event = '$eventId'
            AND am.Accomodation_Name IN ('Own+Arrangement+%28Outside%29', 'Local')
            AND da.Accomodation_Status = 'Allocated')+ IFNULL(
            (
            SELECT
                COUNT(*)
            FROM
                temporary_registration
            WHERE
                event_id = '$eventId'
            ),
            0
        )
        ) AS OwnArrangement_Local_Count";
        return $query;
    }

}