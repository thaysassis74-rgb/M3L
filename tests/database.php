<?php
declare(strict_types=1);
require dirname(__DIR__).'/private/storage.php';
require dirname(__DIR__).'/private/queue.php';
require __DIR__.'/integrations.php';

$dsn = getenv('M3L_TEST_DSN') ?: '';
if (!preg_match('/dbname=m3l_test_[a-z0-9_]+(?:;|$)/', $dsn)) {
    fwrite(STDERR, "Defina M3L_TEST_DSN para um banco DESCARTÁVEL chamado m3l_test_*.\n"); exit(1);
}
$user = getenv('M3L_TEST_USER') ?: 'root';
$password = getenv('M3L_TEST_PASSWORD') ?: '';
$connect = static fn()=>new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
$db = $connect();
$db->exec("SET time_zone = '+00:00'");
$db->exec('DROP TABLE IF EXISTS jobs, surveys, lead_attribution, leads, integration_state');
$db->exec(file_get_contents(dirname(__DIR__).'/database/schema.sql'));
$id = bin2hex(random_bytes(24));
receiveSignup($db, $id, $lead['name'], $lead['email'], $lead['phone']);
receiveSignup($db, $id, $lead['name'], $lead['email'], $lead['phone']);
check((int)$db->query('SELECT COUNT(*) FROM leads')->fetchColumn() === 1 && (int)$db->query('SELECT COUNT(*) FROM jobs')->fetchColumn() === 1, 'cadastro repetido produz um registro e uma tarefa');
receiveSurvey($db, $id, array_fill(0,10,'Resposta'));
receiveSurvey($db, $id, array_fill(0,10,'Resposta'));
check((int)$db->query('SELECT COUNT(*) FROM surveys')->fetchColumn() === 1 && (int)$db->query("SELECT COUNT(*) FROM jobs WHERE integration='sheets'")->fetchColumn() === 1, 'pesquisa salva sem esperar ActiveCampaign e sem duplicar');

// Simula falha entre INSERT do cadastro e INSERT na fila; nada pode ficar parcialmente salvo.
$db->exec("CREATE TRIGGER reject_job BEFORE INSERT ON jobs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test rollback'");
$failedId = bin2hex(random_bytes(24));
try { receiveSignup($db, $failedId, 'Falha Local', 'fail@example.com', '+5511999999999'); throw new LogicException('Deveria falhar.'); }
catch (PDOException $error) { check(true, 'falha de fila detectada'); }
$query = $db->prepare('SELECT COUNT(*) FROM leads WHERE id=?'); $query->execute([$failedId]);
check((int)$query->fetchColumn() === 0, 'rollback não deixa lead sem tarefa');
$db->exec('DROP TRIGGER reject_job');

$calls = 0;
$retryClient = new Integrations($config, static function ($method, $url, $headers, $payload) use (&$calls): array {
    $calls++;
    if ($calls === 1) throw new DeliveryFailure('HTTP 429', false, 120);
    if (str_contains($url, 'contact/sync')) return ['contact'=>['id'=>'42']];
    if (str_contains($url, '/contactLists')) return ['contactLists'=>[['list'=>'6','status'=>'1']]];
    if (str_contains($url, '/contactTags')) return ['contactTags'=>[['tag'=>'1']]];
    throw new LogicException('Chamada inesperada.');
});
$worker = new QueueWorker($db, 'activecampaign', $retryClient);
check($worker->acquire(), 'primeiro worker adquire lock');
$other = new QueueWorker($connect(), 'activecampaign', $retryClient);
check(!$other->acquire(), 'segundo worker da mesma integração é impedido');
check($worker->tick(), 'tarefa é processada');
$job = $db->query("SELECT * FROM jobs WHERE integration='activecampaign'")->fetch();
check($job['status'] === 'pending' && (int)$job['attempts'] === 1, '429 mantém cadastro e reagenda tarefa');
check(!$worker->tick() && $calls === 1, 'cooldown impede martelar API durante Retry-After');
$db->exec("UPDATE integration_state SET resume_at=UTC_TIMESTAMP(); UPDATE jobs SET available_at=UTC_TIMESTAMP()");
check($worker->tick() && $db->query("SELECT status FROM jobs WHERE integration='activecampaign'")->fetchColumn() === 'done', 'retomada conclui entrega');
$worker->release();
$db->exec("UPDATE jobs SET status='processing' WHERE integration='activecampaign'");
check($worker->acquire() && $worker->tick(), 'reinício recupera tarefa interrompida');
$worker->release();
$sheetWorker = new QueueWorker($db, 'sheets', new Integrations($config, static fn()=>['ok'=>true]));
check($sheetWorker->acquire() && $sheetWorker->tick(), 'fila independente da pesquisa conclui');
$sheetWorker->release();
$db->exec("UPDATE jobs SET status='pending', attempts=11 WHERE integration='activecampaign'");
$failWorker = new QueueWorker($db, 'activecampaign', new Integrations($config, static function (): array { throw new DeliveryFailure('HTTP 503'); }));
check($failWorker->acquire() && $failWorker->tick(), 'última tentativa executada');
check($db->query("SELECT status FROM jobs WHERE integration='activecampaign'")->fetchColumn() === 'pending', 'falha temporária continua pendente após 12 tentativas');
$failWorker->release();
echo "Testes transacionais concluídos. Banco descartável mantido para inspeção.\n";

$db->exec("UPDATE integration_state SET resume_at=UTC_TIMESTAMP(); UPDATE jobs SET status='pending', available_at=UTC_TIMESTAMP() WHERE integration='activecampaign'");
$blockedWorker = new QueueWorker($db, 'activecampaign', new Integrations($config, static function (): array { throw new DeliveryFailure('Contato com restrição de inscrição.', true); }));
check($blockedWorker->acquire() && $blockedWorker->tick(), 'restrição permanente é analisada');
check($db->query("SELECT status FROM jobs WHERE integration='activecampaign'")->fetchColumn() === 'failed', 'restrição permanente não é repetida automaticamente');
$blockedWorker->release();
$db->exec("UPDATE jobs SET status='processing', attempts=20 WHERE integration='activecampaign'");
check($worker->acquire(), 'worker retoma depois de muitas tentativas');
check($db->query("SELECT status FROM jobs WHERE integration='activecampaign'")->fetchColumn() === 'pending', 'reinício não abandona tarefa com mais de 12 tentativas');
$worker->release();

// Uma tarefa antiga com muitas falhas não impõe seu backoff a todas as outras.
$db->exec("UPDATE integration_state SET resume_at=UTC_TIMESTAMP(); UPDATE jobs SET status='pending',attempts=20,available_at=UTC_TIMESTAMP() WHERE integration='activecampaign'");
$nextId=bin2hex(random_bytes(24));
receiveSignup($db,$nextId,'Outro Cadastro','next@example.com','+5511999999998');
$fairClient=new Integrations($config,static function($method,$url,$headers,$payload): array {
    if(str_contains($url,'contact/sync')) {
        if($payload['contact']['email']!=='next@example.com') throw new DeliveryFailure('HTTP 503');
        return ['contact'=>['id'=>'43']];
    }
    if(str_contains($url,'/contactLists')) return ['contactLists'=>[['list'=>'6','status'=>'1']]];
    if(str_contains($url,'/contactTags')) return ['contactTags'=>[['tag'=>'1']]];
    throw new LogicException('Chamada inesperada.');
});
$fairWorker=new QueueWorker($db,'activecampaign',$fairClient);
check($fairWorker->acquire() && $fairWorker->tick(),'falha de tarefa antiga é reagendada');
$pause=(int)$db->query("SELECT TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),resume_at) FROM integration_state WHERE integration='activecampaign'")->fetchColumn();
check($pause<=60,'backoff de horas não bloqueia a fila por mais de 60s sem Retry-After');
$db->exec("UPDATE integration_state SET resume_at=UTC_TIMESTAMP()");
check($fairWorker->tick(),'cadastro seguinte é processado sem esperar o backoff do anterior');
$q=$db->prepare("SELECT status FROM jobs WHERE integration='activecampaign' AND entity_id=?");$q->execute([$nextId]);
check($q->fetchColumn()==='done','cadastro saudável entregue mesmo com tarefa antiga pendente');
$fairWorker->release();

// Exercita o comando real de reparo somente neste banco descartável.
$db->exec("UPDATE jobs SET status='failed',last_error='HTTP 503; cURL 0' WHERE integration='activecampaign'");
$remove=$db->prepare("DELETE FROM jobs WHERE integration='activecampaign' AND entity_id=?");$remove->execute([$nextId]);
putenv('DB_DSN='.$dsn);putenv('DB_USER='.$user);putenv('DB_PASSWORD='.$password);
$command=escapeshellarg(PHP_BINARY).' -c '.escapeshellarg(php_ini_loaded_file() ?: '').' '.escapeshellarg(dirname(__DIR__).'/bin/reconcile.php');
exec($command,$repairOutput,$repairStatus);
check($repairStatus===0,'reconciliação real executa sem erro');
$summary=json_decode(implode("\n",$repairOutput),true,512,JSON_THROW_ON_ERROR);
check($summary['activecampaign_recovered']===1 && $summary['transient_requeued']===1,'reconciliação repõe tarefa ausente e recupera falha transitória antiga');
check((int)$db->query('SELECT COUNT(*) FROM leads')->fetchColumn()===2,'reconciliação preserva todos os cadastros');
$secondOutput=[];exec($command,$secondOutput,$secondStatus);
$second=json_decode(implode("\n",$secondOutput),true,512,JSON_THROW_ON_ERROR);
check($secondStatus===0 && array_sum($second)===0,'reconciliação repetida não duplica tarefas');
