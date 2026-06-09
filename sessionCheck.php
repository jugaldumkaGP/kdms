<?php

declare(strict_types=1);

// Central session gates for KDMS UI and JSON backends (see includes/web_session.php, includes/api_session.php).

$debug = false;
$result = true;

$config_data = include __DIR__ . '/site_config.php';

if ($debug) {
    echo '<br>current page ID: ';
    var_dump($current_page_id ?? null);
    echo '<br>Search result of current page ID: ';
    var_dump(isset($_SESSION['Access']) ? explode(',', (string) $_SESSION['Access']) : []);
    var_dump(isset($current_page_id, $_SESSION['Access'])
        ? array_search($current_page_id, explode(',', (string) $_SESSION['Access']), true)
        : false);
    echo '<br>entire session: ';
    var_dump($_SESSION ?? []);
    echo '<br>';
}

// ── Collect the reason(s) a check fails so we can log them precisely ──────────
$failReasons = [];

if (session_status() === PHP_SESSION_DISABLED) {
    $result = false;
    $failReasons[] = 'sessions_disabled';
} else {
    if (! isset($_SESSION['eventDesc'])) {
        $result = false;
        $failReasons[] = 'eventDesc_missing';
    } elseif ($_SESSION['eventDesc'] === '') {
        $result = false;
        $failReasons[] = 'eventDesc_empty';
    }

    if ($debug) {
        echo '<br> result : ';
        var_dump($result);
    }

    if (! isset($_SESSION['LoginID'])) {
        $result = false;
        $failReasons[] = 'LoginID_missing';
    } elseif ($_SESSION['LoginID'] === '') {
        $result = false;
        $failReasons[] = 'LoginID_empty';
    }

    if ($debug) {
        echo '<br> result : ';
        var_dump($result);
    }

    if (! isset($_SESSION['Role'])) {
        $result = false;
        $failReasons[] = 'Role_missing';
    } elseif ($_SESSION['Role'] === '') {
        $result = false;
        $failReasons[] = 'Role_empty';
    }

    if ($debug) {
        echo '<br> result : ';
        var_dump($result);
    }

    if ($config_data['check_access']) {
        if (! isset($_SESSION['Access'])) {
            // Access key missing entirely — only hard-fail if the user also has no LoginID
            // (i.e. truly unauthenticated).  If LoginID is present but Access is absent, we
            // treat it as a permissions problem rather than a session problem so the session
            // is not destroyed by the subsequent login.php GET redirect.
            if ($result) {
                // LoginID and Role passed — user is authenticated but has no permissions.
                require_once __DIR__ . '/includes/kdms_log.php';
                kdms_log('WARNING', 'KDMS authenticated user has no Access grants', [
                    'LoginID'      => $_SESSION['LoginID'] ?? '',
                    'Role'         => $_SESSION['Role'] ?? '',
                    'page_id'      => $current_page_id ?? '',
                    'session_id'   => session_id(),
                ]);
                if (defined('KDMS_AUTH_RESPONSE_JSON') && KDMS_AUTH_RESPONSE_JSON === true) {
                    if (! headers_sent()) {
                        header('Content-Type: application/json; charset=utf-8');
                        header('HTTP/1.1 403 Forbidden');
                    }
                    echo '{"ok":false,"error":"no_access_grants"}';
                    exit;
                }
                echo '<b>Your account has no access grants. Please contact the administrator.</b>';
                exit;
            }
            $failReasons[] = 'Access_missing';
        } elseif ($_SESSION['Access'] === '') {
            // Same logic: empty Access string means no grants.
            if ($result) {
                require_once __DIR__ . '/includes/kdms_log.php';
                kdms_log('WARNING', 'KDMS authenticated user has empty Access grants', [
                    'LoginID'      => $_SESSION['LoginID'] ?? '',
                    'Role'         => $_SESSION['Role'] ?? '',
                    'page_id'      => $current_page_id ?? '',
                    'session_id'   => session_id(),
                ]);
                if (defined('KDMS_AUTH_RESPONSE_JSON') && KDMS_AUTH_RESPONSE_JSON === true) {
                    if (! headers_sent()) {
                        header('Content-Type: application/json; charset=utf-8');
                        header('HTTP/1.1 403 Forbidden');
                    }
                    echo '{"ok":false,"error":"no_access_grants"}';
                    exit;
                }
                echo '<b>Your account has no access grants. Please contact the administrator.</b>';
                exit;
            }
            $failReasons[] = 'Access_empty';
        } elseif (isset($current_page_id)) {
            $accessParts = explode(',', (string) $_SESSION['Access']);
            if (! in_array($current_page_id, $accessParts, true)) {
                require_once __DIR__ . '/includes/kdms_log.php';
                kdms_log('WARNING', 'KDMS page ACL denied', [
                    'page_id'    => $current_page_id,
                    'LoginID'    => $_SESSION['LoginID'] ?? '',
                    'session_id' => session_id(),
                ]);
                if (defined('KDMS_AUTH_RESPONSE_JSON') && KDMS_AUTH_RESPONSE_JSON === true) {
                    if (! headers_sent()) {
                        header('Content-Type: application/json; charset=utf-8');
                        header('HTTP/1.1 403 Forbidden');
                    }
                    echo '{"ok":false,"error":"forbidden"}';
                    exit;
                }
                echo '<b>YOU DON\'T HAVE ACCESS TO THIS PAGE!!</b>';
                exit;
            }
        }
    }
}

if ($debug) {
    echo '<br> result : ';
    var_dump($result);
}

if (! $result) {
    require_once __DIR__ . '/includes/kdms_log.php';
    kdms_log('NOTICE', 'KDMS session check failed', [
        'reasons'    => implode(',', $failReasons),
        'session_id' => session_id(),
        'has_login'  => isset($_SESSION['LoginID']) ? (string) $_SESSION['LoginID'] : 'MISSING',
        'has_role'   => isset($_SESSION['Role']) ? (string) $_SESSION['Role'] : 'MISSING',
        'event_desc' => isset($_SESSION['eventDesc'])
            ? (strlen((string) $_SESSION['eventDesc']) > 0 ? 'set' : 'EMPTY')
            : 'MISSING',
        'script'     => basename($_SERVER['SCRIPT_FILENAME'] ?? ''),
        'page_id'    => $current_page_id ?? '',
    ]);

    if (defined('KDMS_AUTH_RESPONSE_JSON') && KDMS_AUTH_RESPONSE_JSON === true) {
        if (! headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('HTTP/1.1 401 Unauthorized');
        }
        echo '{"ok":false,"error":"unauthenticated"}';

        exit;
    }

    $url = $config_data['webroot'] . 'UI/login.php';

    header('Location: ' . $url);

    exit;
}
