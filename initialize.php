<?php

require_once __DIR__ . '/includes/kdms_log.php';

kdms_log_bootstrap();

$debug = false;
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if ($debug) {
    echo 'current session ID: ', session_id(), '<br>', 'session_status: ', session_status(), '<br>';
}

$config_data = include __DIR__ . '/site_config.php';
include_once __DIR__ . '/Logic/clsOptionHandler.php';

//Fetch Event Description once and set to Session variable
$eventID = $config_data['event_id'];

if ($debug) {echo "eventID:", $eventID, "<br>";}

if(!isset($_SESSION["eventDesc"])){
    $_SESSION["eventDesc"] = "";
    if ($debug) {echo "Reset event desc to blank.", "<br>";}
}
if ($debug) {echo "event ID: ", $eventID, "Event desc: ", $_SESSION["eventDesc"] , "<br>";}
if( $eventID == ""){$_SESSION["eventDesc"] = "Event not set";}

if ($_SESSION["eventDesc"] === '') {
    if (defined('KDMS_SKIP_OPTION_PRELOAD') && KDMS_SKIP_OPTION_PRELOAD === true) {
        // API/bootstrap path: skip server-side CURL to loadOptions.php (would recurse via api_session).
        $_SESSION["eventDesc"] = $eventID !== '' ? $eventID : 'Event not set';
    } else {
        if ($debug) {
            echo "Repopulating event description..", "<br>";
        }
        // Same PHP instance holds the session lock; child Apache request (loadOptions.php) would block
        // on session_start() forever. Release the lock via kdms_begin_internal_apache_curl() so that
        // the shutdown function is registered — this ensures the session is always restarted even if
        // a fatal error or max_execution_time fires before kdms_end_internal_apache_curl() is called.
        // kdms_internal_http.php is already loaded by Logic/clsOptionHandler.php above.
        kdms_begin_internal_apache_curl();

        $optionHandler = new clsOptionHandler('EventDetail');
        $optionHandler->setOptionKey($eventID);
        $optionHandler->setEventId($eventID);
        $response = $optionHandler->getOptions();
        // getOptionsFromAPI() already called kdms_end_internal_apache_curl() which restarts the
        // session; calling it again here is a safe no-op when the session is already active.
        kdms_end_internal_apache_curl();

        if ($debug) {
            echo '<br> Response: ';
            var_dump($response);
        }
        if (is_array($response) && !empty($response['Event_Status'])) {
            if ($response['Event_Status'] === 'Current') {
                if (!empty($response['Event_Description'])) {
                    $_SESSION['eventDesc'] = urldecode((string) $response['Event_Description']);
                    if ($debug) {
                        echo 'setting session to: ', $_SESSION['eventDesc'], '<br>';
                    }
                } else {
                    $_SESSION['eventDesc'] = 'Event not found';
                    if ($debug) {
                        echo 'session not set', '<br>';
                    }
                }
            } else {
                $_SESSION['eventDesc'] = 'Event ID ' . $eventID . ' is not current.' . ' <br> ' . '  Please use Event Manager page.';
                if ($debug) {
                    echo 'session not set', '<br>';
                }
            }
        } else {
            $_SESSION['eventDesc'] = 'Event not found.' . ' <br> ' . ' Please use Event Manager page to initialize.';
            if ($debug) {
                echo 'session not set', '<br>';
            }
        }
    }
}
if ($debug) {
    echo 'eventDesc:';
    echo $_SESSION['eventDesc'], '<br>';
    die;
}
