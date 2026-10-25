<?php
declare(strict_types=1);

function database(): PDO
{
    $path = __DIR__.'/database.php';
    $settings = is_file($path) ? require $path : [];
    $dsn = getenv('DB_DSN') ?: ($settings['dsn'] ?? '');
    $user = getenv('DB_USER') ?: ($settings['user'] ?? '');
    $password = getenv('DB_PASSWORD') ?: ($settings['password'] ?? '');
    if (!str_starts_with($dsn, 'mysql:')) {
        throw new RuntimeException('Configure o banco MySQL.');
    }
    $db = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $db->exec("SET time_zone = '+00:00'");
    return $db;
}

function enqueue(PDO $db, string $integration, string $entity): void
{
    $query = $db->prepare('INSERT INTO jobs (integration, entity_id) VALUES (?, ?)');
    $query->execute([$integration, $entity]);
}

// O ID nasce na sessão ANTES da transação; uma resposta perdida pode ser repetida.
function receiveSignup(PDO $db, string $id, string $name, string $email, string $phone, array $utms = []): void
{
    $db->beginTransaction();
    try {
        $query = $db->prepare('INSERT INTO leads (id, name, email, phone) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id');
        $query->execute([$id, $name, $email, $phone]);
        // Mantém a atribuição original quando o mesmo cadastro é reenviado.
        $query = $db->prepare('INSERT INTO lead_attribution (lead_id, utms) VALUES (?, ?) ON DUPLICATE KEY UPDATE lead_id = lead_id');
        $query->execute([$id, json_encode($utms, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        $query = $db->prepare("INSERT INTO jobs (integration, entity_id) VALUES ('activecampaign', ?) ON DUPLICATE KEY UPDATE id = id");
        $query->execute([$id]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function receiveSurvey(PDO $db, string $leadId, array $answers): void
{
    $db->beginTransaction();
    try {
        // Uma pesquisa por cadastro; serializa tentativas em paralelo/repetidas.
        $query = $db->prepare('SELECT id FROM leads WHERE id = ? FOR UPDATE');
        $query->execute([$leadId]);
        if (!$query->fetch()) throw new RuntimeException('Cadastro local não encontrado.');
        $query = $db->prepare('SELECT id FROM surveys WHERE lead_id = ?');
        $query->execute([$leadId]);
        if (!$query->fetch()) {
            $id = bin2hex(random_bytes(24));
            $query = $db->prepare('INSERT INTO surveys (id, lead_id, answers) VALUES (?, ?, ?)');
            $query->execute([$id, $leadId, json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            enqueue($db, 'sheets', $id);
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

