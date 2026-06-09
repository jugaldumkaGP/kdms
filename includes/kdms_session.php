<?php

declare(strict_types=1);

/**
 * Bootstrap-only entry for session storage defined in web_session.php.
 */

if (! defined('KDMS_SESSION_BOOTSTRAP_ONLY')) {
    define('KDMS_SESSION_BOOTSTRAP_ONLY', true);
}

require_once __DIR__ . '/web_session.php';
