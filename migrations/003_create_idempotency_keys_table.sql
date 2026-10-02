CREATE TABLE IF NOT EXISTS idempotency_keys (
    idem_key      VARCHAR(64)       NOT NULL,
    request_hash  CHAR(64)          NOT NULL,
    status_code   SMALLINT UNSIGNED NOT NULL,
    response_body MEDIUMTEXT        NOT NULL,
    created_at    TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (idem_key),
    KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
