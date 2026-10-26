<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/private/storage.php';
$db=database();
// Só cria tarefas ausentes: não reenvia concluídos nem remove registros.
$db->beginTransaction();
try {
    $ac=$db->exec("INSERT INTO jobs (integration,entity_id) SELECT 'activecampaign',l.id FROM leads l LEFT JOIN jobs j ON j.integration='activecampaign' AND j.entity_id=l.id WHERE j.id IS NULL ON DUPLICATE KEY UPDATE id=jobs.id");
    $sheets=$db->exec("INSERT INTO jobs (integration,entity_id) SELECT 'sheets',s.id FROM surveys s LEFT JOIN jobs j ON j.integration='sheets' AND j.entity_id=s.id WHERE j.id IS NULL ON DUPLICATE KEY UPDATE id=jobs.id");
    // Recupera somente falhas transitórias legadas que atingiram o limite antigo.
    $retry=$db->exec("UPDATE jobs SET status='pending', attempts=0, available_at=UTC_TIMESTAMP(), started_at=NULL, finished_at=NULL WHERE status='failed' AND (last_error REGEXP '^HTTP (0|408|425|429|5[0-9][0-9]); cURL [0-9]+$' OR last_error='Resposta JSON inválida.' OR last_error='Processamento interrompido; retomado pelo worker.')");
    $db->commit();
    echo json_encode(['activecampaign_recovered'=>$ac,'sheets_recovered'=>$sheets,'transient_requeued'=>$retry]),PHP_EOL;
} catch(Throwable $error) { if($db->inTransaction())$db->rollBack(); throw $error; }
