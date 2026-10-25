<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/private/storage.php';
database()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS lead_attribution (
    lead_id CHAR(48) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    utms JSON NOT NULL,
    FOREIGN KEY (lead_id) REFERENCES leads(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL
);
echo "Armazenamento de UTMs pronto.\n";
