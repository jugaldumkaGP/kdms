<?php

declare(strict_types=1);

/**
 * Dedicated logout endpoint.
 *
 * Previously the app used login.php (GET) as the logout mechanism, meaning any
 * accidental navigation to login.php — browser back-button, extension prefetch,
 * misclick — would silently destroy the active session.  This file is the single
 * authorised place that clears the session, so login.php no longer needs to do it.
 */

require_once dirname(__DIR__) . '/includes/kdms_log.php';

session_start();

$loggedInAs = $_SESSION['LoginID'] ?? '';

session_unset();
session_destroy();

// Regenerate ID so the browser's old session cookie cannot be reused.
session_start();
session_regenerate_id(true);

kdms_log('NOTICE', 'KDMS user logged out', ['LoginID' => $loggedInAs !== '' ? $loggedInAs : '(unknown)']);

$config_data = include dirname(__DIR__) . '/site_config.php';
$loginUrl = $config_data['webroot'] . 'UI/login.php';

header('Location: ' . $loginUrl);
exit;
