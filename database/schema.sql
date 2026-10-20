-- MySQL 8+ ou MariaDB 10.6+. Importar uma vez antes de ativar o novo api.php.
CREATE TABLE IF NOT EXISTS leads (
    id CHAR(48) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    email VARCHAR(254) NOT NULL,
    phone VARCHAR(16) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS surveys (
    id CHAR(48) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    lead_id CHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    answers JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lead_id) REFERENCES leads(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    integration VARCHAR(16) CHARACTER SET ascii NOT NULL,
    entity_id CHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    last_error VARCHAR(200) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_delivery (integration, entity_id),
    KEY ready_jobs (integration, status, available_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS integration_state (
    integration VARCHAR(16) CHARACTER SET ascii PRIMARY KEY,
    resume_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO integration_state (integration) VALUES ('activecampaign'), ('sheets');
