<?php

declare(strict_types=1);

/**
 * Mandatory session authentication for KDMS endpoints that return JSON or non-redirect responses.
 *
 * Session storage is bootstrapped in initialize.php via includes/web_session.php (database
 * when SESSION_DRIVER=database). X-KDMS-SERVICE-KEY trusted-service auth is unchanged.
 */

require_once __DIR__ . '/kdms_log.php';
kdms_log_bootstrap();

if (! defined('KDMS_AUTH_RESPONSE_JSON')) {
    define('KDMS_AUTH_RESPONSE_JSON', true);
}

unset($current_page_id, $GLOBALS['current_page_id']);

/*
 * Prevent initialize.php from curling into JSON APIs (which include this file): that caused
 * nested HTTP back to loadOptions.php and exhaustion of Apache MaxRequestWorkers.
 */
if (! defined('KDMS_SKIP_OPTION_PRELOAD')) {
    define('KDMS_SKIP_OPTION_PRELOAD', true);
}

$trustedService = false;
$serviceKeyRejected = false;
$configuredServiceKey = getenv('KDMS_SERVICE_KEY');
if (is_string($configuredServiceKey) && $configuredServiceKey !== '') {
    $headerServiceKey = '';
    if (!empty($_SERVER['HTTP_X_KDMS_SERVICE_KEY'])) {
        $headerServiceKey = (string) $_SERVER['HTTP_X_KDMS_SERVICE_KEY'];
    } elseif (!empty($_REQUEST['service_key'])) {
        $headerServiceKey = (string) $_REQUEST['service_key'];
    }

    if ($headerServiceKey !== '' && hash_equals($configuredServiceKey, $headerServiceKey)) {
        $trustedService = true;
        define('KDMS_TRUSTED_SERVICE_AUTH', true);
    } elseif ($headerServiceKey !== '') {
        $serviceKeyRejected = true;
    }
}

if ($serviceKeyRejected) {
    if (! headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('HTTP/1.1 401 Unauthorized');
    }
    echo json_encode(['ok' => false, 'error' => 'invalid_service_key']);

    exit;
}

require_once dirname(__DIR__) . '/initialize.php';
if (! $trustedService) {
    require_once dirname(__DIR__) . '/sessionCheck.php';
}
