<?php

declare(strict_types=1);

/**
 * KDMS web UI session storage and mandatory page auth.
 *
 * SessionHandlerInterface + kdms_session_start() live here and are loaded before session_start()
 * via includes/kdms_session.php (initialize.php, login.php, logout.php). API paths use the same
 * handler through initialize.php (includes/api_session.php).
 */

if (! function_exists('kdms_session_start')) {

    final class KdmsDatabaseSessionHandler implements SessionHandlerInterface
    {
        private ?PDO $pdo = null;

        private bool $transactionOpen = false;

        private int $lifetime;

        public function __construct(int $lifetime)
        {
            $this->lifetime = $lifetime;
        }

        private function connect(): PDO
        {
            if ($this->pdo instanceof PDO) {
                return $this->pdo;
            }

            require_once dirname(__DIR__) . '/api/config/database.php';

            $database = new Database();
            $pdo = $database->getConnection();
            if (! $pdo instanceof PDO) {
                throw new RuntimeException('KDMS session handler: database connection failed');
            }

            $this->pdo = $pdo;

            return $this->pdo;
        }

        public function open(string $path, string $name): bool
        {
            $this->connect();

            if (random_int(1, 100) === 1) {
                $this->gc($this->lifetime);
            }

            return true;
        }

        public function close(): bool
        {
            if ($this->transactionOpen && $this->pdo instanceof PDO) {
                $this->pdo->commit();
                $this->transactionOpen = false;
            }

            $this->pdo = null;

            return true;
        }

        public function read(string $id): string
        {
            $pdo = $this->connect();

            if (! $this->transactionOpen) {
                $pdo->beginTransaction();
                $this->transactionOpen = true;
            }

            $cutoff = time() - $this->lifetime;
            $stmt = $pdo->prepare(
                'SELECT payload FROM sessions WHERE id = :id AND last_activity >= :cutoff FOR UPDATE'
            );
            $stmt->execute([
                'id' => $id,
                'cutoff' => $cutoff,
            ]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (! is_array($row) || ! isset($row['payload'])) {
                return '';
            }

            return (string) $row['payload'];
        }

        public function write(string $id, string $data): bool
        {
            $pdo = $this->connect();
            $now = time();

            $stmt = $pdo->prepare(
                'INSERT INTO sessions (id, user_id, ip_address, user_agent, payload, last_activity)
                 VALUES (:id, :user_id, :ip_address, :user_agent, :payload, :last_activity)
                 ON DUPLICATE KEY UPDATE
                   user_id = VALUES(user_id),
                   ip_address = VALUES(ip_address),
                   user_agent = VALUES(user_agent),
                   payload = VALUES(payload),
                   last_activity = VALUES(last_activity)'
            );

            return $stmt->execute([
                'id' => $id,
                'user_id' => self::extractUserIdFromPayload($data),
                'ip_address' => self::clientIpAddress(),
                'user_agent' => self::clientUserAgent(),
                'payload' => $data,
                'last_activity' => $now,
            ]);
        }

        public function destroy(string $id): bool
        {
            $pdo = $this->connect();
            $stmt = $pdo->prepare('DELETE FROM sessions WHERE id = :id');

            return $stmt->execute(['id' => $id]);
        }

        public function gc(int $max_lifetime): int|false
        {
            $pdo = $this->connect();
            $cutoff = time() - $max_lifetime;
            $stmt = $pdo->prepare('DELETE FROM sessions WHERE last_activity < :cutoff');
            $stmt->execute(['cutoff' => $cutoff]);

            return $stmt->rowCount();
        }

        private static function extractUserIdFromPayload(string $data): ?int
        {
            if ($data === '') {
                return null;
            }

            $loginId = null;
            if (preg_match('/LoginID\|s:\d+:"([^"]*)";/', $data, $matches) === 1) {
                $loginId = $matches[1];
            } elseif (preg_match('/LoginID\|i:(\d+);/', $data, $matches) === 1) {
                return (int) $matches[1];
            }

            if ($loginId === null || $loginId === '' || ! ctype_digit($loginId)) {
                return null;
            }

            return (int) $loginId;
        }

        private static function clientIpAddress(): ?string
        {
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            if (! is_string($ip) || $ip === '') {
                return null;
            }

            return substr($ip, 0, 45);
        }

        private static function clientUserAgent(): ?string
        {
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
            if (! is_string($ua) || $ua === '') {
                return null;
            }

            return $ua;
        }
    }

    function kdms_session_lifetime(): int
    {
        $configured = getenv('SESSION_LIFETIME');
        if (is_string($configured) && $configured !== '' && ctype_digit($configured)) {
            return (int) $configured;
        }

        return 28800;
    }

    function kdms_session_driver(): string
    {
        $driver = getenv('SESSION_DRIVER');

        return is_string($driver) && $driver !== ''
            ? strtolower(trim($driver))
            : 'cookie';
    }

    function kdms_is_https_request(): bool
    {
        if (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        $forwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';

        return is_string($forwarded) && strtolower($forwarded) === 'https';
    }

    function kdms_bootstrap_session(): void
    {
        static $bootstrapped = false;
        if ($bootstrapped) {
            return;
        }
        $bootstrapped = true;

        $lifetime = kdms_session_lifetime();

        ini_set('session.gc_maxlifetime', (string) $lifetime);
        ini_set('session.cookie_lifetime', (string) $lifetime);
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_secure', kdms_is_https_request() ? '1' : '0');

        if (kdms_session_driver() === 'database') {
            $handler = new KdmsDatabaseSessionHandler($lifetime);
            session_set_save_handler($handler, true);
        }
    }

    function kdms_session_start(): void
    {
        kdms_bootstrap_session();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}

if (defined('KDMS_SESSION_BOOTSTRAP_ONLY')) {
    return;
}

/**
 * Mandatory auth for KDMS HTML pages. Include as the very first dependency (before any output).
 */

require_once __DIR__ . '/kdms_log.php';
kdms_log_bootstrap();

$scriptBasename = basename($_SERVER['SCRIPT_FILENAME'] ?? '');

if ($scriptBasename === 'login.php') {
    throw new RuntimeException('Do not load web_session.php from login.php');
}

$pageMapPath = __DIR__ . '/kdms_web_page_ids.php';

if (! is_readable($pageMapPath)) {
    kdms_log('ERROR', 'kdms_web_page_ids.php missing', []);
    http_response_code(500);
    echo 'KDMS configuration error.';
    exit;
}

/** @var array<string, string> $pageMap */
$pageMap = require $pageMapPath;

if (! isset($pageMap[$scriptBasename])) {
    kdms_log('ERROR', 'Missing web page ACL mapping', ['script' => $scriptBasename]);
    http_response_code(500);
    echo 'KDMS configuration error: missing page mapping for this URL.';
    exit;
}

$current_page_id = $pageMap[$scriptBasename];

require_once dirname(__DIR__) . '/initialize.php';
require_once dirname(__DIR__) . '/sessionCheck.php';

/*
 * PHP → Apache CURL helpers (kdms_begin/kdms_end) call session_write_close() then session_start().
 * If markup was already echoed to the browser, php.ini may forbid sending session cookies afterward.
 * Starting one output buffer defers flushing so HTTP headers (and cookie headers) remain sendable until
 * the buffer is flushed — required for authenticated internal curls on UI pages after any output.
 */
if (function_exists('ob_start') && ob_get_level() === 0) {
    ob_start();
}
