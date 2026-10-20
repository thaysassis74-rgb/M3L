<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/private/storage.php';
require dirname(__DIR__).'/private/queue.php';

$integration = $argv[1] ?? '';
if (!in_array($integration, ['activecampaign', 'sheets'], true)) {
    fwrite(STDERR, "Uso: php bin/worker.php activecampaign|sheets [--once]\n"); exit(1);
}
try {
    $config = require dirname(__DIR__).'/private/config.php';
    $worker = new QueueWorker(database(), $integration, new Integrations($config));
    if (!$worker->acquire()) { fwrite(STDERR, "Já existe um worker desta integração.\n"); exit(1); }
    try {
        do {
            $worked = $worker->tick();
            if (in_array('--once', $argv, true)) break;
            if (!$worked) usleep(500000);
        } while (true);
    } finally {
        $worker->release();
    }
} catch (Throwable $error) {
    // Nenhum payload, resposta externa, credencial ou e-mail no log.
    fwrite(STDERR, 'Worker interrompido ('.get_class($error)."). Confira banco e configuração.\n");
    exit(1);
}
