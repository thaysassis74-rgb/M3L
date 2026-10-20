<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/private/storage.php';
$db = database();
if (($argv[1] ?? '') === 'retry' && ctype_digit($argv[2] ?? '')) {
    $query = $db->prepare("UPDATE jobs SET status = 'pending', attempts = 0, available_at = UTC_TIMESTAMP(), started_at = NULL, finished_at = NULL WHERE id = ? AND status = 'failed'");
    $query->execute([$argv[2]]);
    echo 'Reagendadas: '.$query->rowCount().PHP_EOL;
} elseif (($argv[1] ?? '') === 'failed') {
    $rows = $db->query("SELECT id, integration, attempts, last_error FROM jobs WHERE status = 'failed' ORDER BY id DESC LIMIT 50")->fetchAll();
    echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
} else {
    $rows = $db->query('SELECT integration, status, COUNT(*) AS total, MIN(created_at) AS oldest FROM jobs GROUP BY integration, status')->fetchAll();
    echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
}
