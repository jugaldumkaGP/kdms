-- Phase: shared database-backed PHP sessions (kdms-prod + kdms-api-prod on Cloud Run)
CREATE TABLE IF NOT EXISTS sessions (
    id            VARCHAR(255) NOT NULL PRIMARY KEY,
    user_id       BIGINT UNSIGNED DEFAULT NULL,
    ip_address    VARCHAR(45) DEFAULT NULL,
    user_agent    TEXT DEFAULT NULL,
    payload       LONGTEXT NOT NULL,
    last_activity INT NOT NULL,
    INDEX idx_sessions_last_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
