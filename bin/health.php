<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/private/storage.php';
$db = database();
$rows = $db->query("SELECT integration, status, COUNT(*) total, MAX(TIMESTAMPDIFF(SECOND,created_at,UTC_TIMESTAMP())) oldest_seconds, MAX(attempts) max_attempts FROM jobs GROUP BY integration,status")->fetchAll();
$missing = (int)$db->query("SELECT COUNT(*) FROM leads l LEFT JOIN jobs j ON j.entity_id=l.id AND j.integration='activecampaign' WHERE j.id IS NULL")->fetchColumn();
$missingSheets = (int)$db->query("SELECT COUNT(*) FROM surveys s LEFT JOIN jobs j ON j.entity_id=s.id AND j.integration='sheets' WHERE j.id IS NULL")->fetchColumn();
$issues = [];
foreach ($rows as $row) {
    if ($row['status']==='failed' || ($row['status']==='pending' && (int)$row['oldest_seconds']>600)) $issues[]=$row['integration'].': '.$row['status'].' ('.$row['total'].')';
}
$stuck = (int)$db->query("SELECT COUNT(*) FROM jobs WHERE status='processing' AND started_at < UTC_TIMESTAMP()-INTERVAL 10 MINUTE")->fetchColumn();
if ($stuck) $issues[]='Tarefas em processamento há mais de 10 minutos: '.$stuck;
if ($missing) $issues[]='Cadastros sem tarefa: '.$missing;
if ($missingSheets) $issues[]='Pesquisas sem tarefa: '.$missingSheets;
$free = disk_free_space('/var'); $size = disk_total_space('/var');
if ($free !== false && $size && $free/$size<.15) $issues[]='Disco com menos de 15% livre.';
$backupDir = '/var/backups/m3l';
if (is_readable($backupDir)) {
    $backups = glob($backupDir.'/*.sql.gz') ?: [];
    $latest = $backups ? max(array_map('filemtime',$backups)) : 0;
    if (time()-$latest>90000) $issues[]='Backup ausente ou com mais de 25 horas.';
}
echo json_encode(['ok'=>!$issues,'issues'=>$issues,'queue'=>$rows,'missing_activecampaign_jobs'=>$missing,'missing_sheets_jobs'=>$missingSheets], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),PHP_EOL;
exit($issues ? 1 : 0);
