<?php
declare(strict_types=1);
require_once __DIR__.'/integrations.php';

final class QueueWorker
{
    private string $lockName;
    private bool $locked = false;

    public function __construct(private PDO $db, private string $integration, private Integrations $client)
    {
        if (!in_array($integration, ['activecampaign', 'sheets'], true)) throw new InvalidArgumentException('Integração inválida.');
        $database = (string)$db->query('SELECT DATABASE()')->fetchColumn();
        $this->lockName = 'm3l:'.substr(hash('sha256', $database), 0, 16).':'.$integration;
    }

    public function acquire(): bool
    {
        $query = $this->db->prepare('SELECT GET_LOCK(?, 0)');
        $query->execute([$this->lockName]);
        $this->locked = (int)$query->fetchColumn() === 1;
        if ($this->locked) {
            // O lock só é liberado quando o worker anterior termina/perde a conexão.
            // Assim, tarefas interrompidas podem ser retomadas sem aguardar cron.
            $query = $this->db->prepare("UPDATE jobs SET status = 'pending', started_at = NULL, last_error = 'Processamento interrompido; retomado pelo worker.' WHERE integration = ? AND status = 'processing'");
            $query->execute([$this->integration]);
        }
        return $this->locked;
    }

    public function release(): void
    {
        if (!$this->locked) return;
        $query = $this->db->prepare('SELECT RELEASE_LOCK(?)');
        $query->execute([$this->lockName]);
        $this->locked = false;
    }

    public function tick(): bool
    {
        if (!$this->locked) throw new LogicException('Adquira o lock antes de executar.');
        $this->db->beginTransaction();
        try {
            $query = $this->db->prepare('SELECT resume_at > UTC_TIMESTAMP() FROM integration_state WHERE integration = ?');
            $query->execute([$this->integration]);
            if ((bool)$query->fetchColumn()) {
                $this->db->commit();
                return false;
            }
            $query = $this->db->prepare("SELECT * FROM jobs WHERE integration = ? AND status = 'pending' AND available_at <= UTC_TIMESTAMP() ORDER BY available_at, id LIMIT 1 FOR UPDATE");
            $query->execute([$this->integration]);
            $job = $query->fetch();
            if (!$job) {
                $this->db->commit();
                return false;
            }
            $query = $this->db->prepare("UPDATE jobs SET status = 'processing', attempts = LEAST(attempts + 1, 1000000), started_at = UTC_TIMESTAMP() WHERE id = ?");
            $query->execute([$job['id']]);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
        try {
            if ($this->integration === 'activecampaign') {
                $query = $this->db->prepare('SELECT * FROM leads WHERE id = ?');
            } else {
                $query = $this->db->prepare('SELECT s.id, s.answers, l.name, l.email, l.phone, a.utms FROM surveys s JOIN leads l ON l.id = s.lead_id LEFT JOIN lead_attribution a ON a.lead_id = l.id WHERE s.id = ?');
            }
            $query->execute([$job['entity_id']]);
            $entity = $query->fetch();
            if (!$entity) throw new DeliveryFailure('Registro local ausente.', true);
            $this->client->{$this->integration}($entity);
            $query = $this->db->prepare("UPDATE jobs SET status = 'done', finished_at = UTC_TIMESTAMP(), last_error = NULL WHERE id = ?");
            $query->execute([$job['id']]);
        } catch (Throwable $caught) {
            $error = $caught instanceof DeliveryFailure ? $caught : new DeliveryFailure(
                'Falha interna recuperável ('.get_class($caught).').'
            );
            $attempt = (int)$job['attempts'] + 1;
            $failed = $error->permanent; // Falhas temporárias não desistem após 12 tentativas.
            $delay = max($error->retryAfter, min(21600, 15 * (2 ** min(11, $attempt - 1))) + random_int(0, 10));
            // Backoff longo pertence à tarefa, sem bloquear todos os outros cadastros.
            // Retry-After continua sendo respeitado pela integração inteira.
            $pause = $failed ? 0 : max($error->retryAfter, min(60, $delay));
            if ($failed && (str_contains($error->getMessage(), 'Configure ') || preg_match('/HTTP (401|403)/', $error->getMessage()))) $pause = 60;
            $this->db->beginTransaction();
            try {
                $query = $this->db->prepare('UPDATE integration_state SET resume_at = ? WHERE integration = ?');
                $query->execute([gmdate('Y-m-d H:i:s', time() + $pause), $this->integration]);
                $query = $this->db->prepare('UPDATE jobs SET status = ?, available_at = ?, last_error = ? WHERE id = ?');
                $query->execute([$failed ? 'failed' : 'pending', gmdate('Y-m-d H:i:s', time() + $delay), $error->getMessage(), $job['id']]);
                $this->db->commit();
            } catch (Throwable $dbError) {
                if ($this->db->inTransaction()) $this->db->rollBack();
                throw $dbError;
            }
            error_log('M3L '.$this->integration.' job '.$job['id'].': '.$error->getMessage());
        }
        return true;
    }
}

