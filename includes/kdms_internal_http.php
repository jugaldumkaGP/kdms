<?php

declare(strict_types=1);

/**
 * Helpers for PHP → Apache CURL back into this app (/api/*.php behind api_session.php).
 *
 * Without Cookie: PHPSESSID, endpoints return HTTP 401 JSON and the dashboard shows empty data.
 * With the lock held while CURL runs, the child request blocks on session_start() — callers must
 * session_write_close() before CURL and reopen after (shutdown restores for the remainder of output).
 */

function kdms_begin_internal_apache_curl(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $GLOBALS['KDMS_INTERNAL_SESSION_COOKIE'] = session_name() . '=' . session_id();
    session_write_close();

    static $shutdownRegistered = false;
    if ($shutdownRegistered) {
        return;
    }
    $shutdownRegistered = true;
    register_shutdown_function(static function (): void {
        kdms_end_internal_apache_curl();
    });
}

function kdms_end_internal_apache_curl(): void
{
    if (isset($GLOBALS['KDMS_INTERNAL_SESSION_COOKIE'])) {
        unset($GLOBALS['KDMS_INTERNAL_SESSION_COOKIE']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    if (session_status() === PHP_SESSION_DISABLED) {
        return;
    }
    require_once __DIR__ . '/kdms_session.php';
    kdms_session_start();
}

function kdms_curl_setopt_internal_cookie($ch): void
{
    if (! empty($GLOBALS['KDMS_INTERNAL_SESSION_COOKIE'])) {
        curl_setopt($ch, CURLOPT_COOKIE, (string) $GLOBALS['KDMS_INTERNAL_SESSION_COOKIE']);
    }

    $serviceKey = getenv('KDMS_SERVICE_KEY');
    if (is_string($serviceKey) && $serviceKey !== '') {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-KDMS-SERVICE-KEY: ' . $serviceKey]);
    }
}
