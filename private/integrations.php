<?php
declare(strict_types=1);

final class DeliveryFailure extends RuntimeException
{
    public function __construct(string $message, public bool $permanent = false, public int $retryAfter = 0)
    {
        parent::__construct($message);
    }
}

final class Integrations
{
    private ?Closure $transport;

    // O transporte alternativo só é usado pelos testes; não vem da requisição web.
    public function __construct(private array $config, ?Closure $transport = null)
    {
        $this->transport = $transport;
    }

    private function request(string $method, string $url, array $headers, ?array $payload, bool $follow = false): array
    {
        if ($this->transport) return ($this->transport)($method, $url, $headers, $payload);
        $retryAfter = 0;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$retryAfter): int {
                if (stripos($line, 'Retry-After:') === 0) {
                    $value = trim(substr($line, 12));
                    $retryAfter = ctype_digit($value) ? (int)$value : max(0, (strtotime($value) ?: time()) - time());
                }
                return strlen($line);
            },
        ]);
        if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        // Limita o ritmo do único worker desta integração, inclusive em respostas rápidas.
        usleep(400000);
        if ($body === false || $status < 200 || $status >= 300) {
            $permanent = $status >= 400 && $status < 500 && !in_array($status, [408, 425, 429], true);
            throw new DeliveryFailure('HTTP '.$status.'; cURL '.$errno, $permanent, $retryAfter);
        }
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) throw new JsonException('Resposta não é objeto.');
            return $data;
        } catch (JsonException $error) {
            throw new DeliveryFailure('Resposta JSON inválida.');
        }
    }

    private function ac(string $method, string $path, ?array $payload = null): array
    {
        $url = rtrim((string)($this->config['api_url'] ?? ''), '/');
        if (!preg_match('~\Ahttps://[a-zA-Z0-9-]+\.api-us[0-9]+\.com\z~', $url) || empty($this->config['api_key'])) {
            throw new DeliveryFailure('Configure a URL e a chave da ActiveCampaign.', true);
        }
        return $this->request($method, $url.'/api/3/'.$path, ['Api-Token: '.$this->config['api_key']], $payload);
    }

    private function findId(string $resource, string $field, string $name): string
    {
        for ($offset = 0; $offset < 10000; $offset += 100) {
            $data = $this->ac('GET', $resource.'?limit=100&offset='.$offset);
            foreach ($data[$resource] ?? [] as $row) {
                if (($row[$field] ?? '') === $name) return (string)$row['id'];
            }
            if (count($data[$resource] ?? []) < 100) break;
        }
        throw new DeliveryFailure('Lista ou tag não encontrada.', true);
    }

    private function allRelations(string $id, string $resource): array
    {
        $rows = [];
        for ($offset = 0; $offset < 10000; $offset += 100) {
            $data = $this->ac('GET', 'contacts/'.$id.'/'.$resource.'?limit=100&offset='.$offset);
            $page = $data[$resource] ?? null;
            if (!is_array($page)) throw new DeliveryFailure('Relacionamento ausente na resposta.');
            $rows = array_merge($rows, $page);
            if (count($page) < 100) return $rows;
        }
        throw new DeliveryFailure('Paginação excedida; conferir contato.', true);
    }

    public function activecampaign(array $lead): void
    {
        // IDs opcionais evitam procurar lista/tag em todos os envios.
        $list = (string)($this->config['list_id'] ?? '');
        $tag = (string)($this->config['tag_id'] ?? '');
        if (!$list) $list = $this->findId('lists', 'name', $this->config['list_name']);
        if (!$tag) $tag = $this->findId('tags', 'tag', $this->config['tag_name']);
        $parts = explode(' ', $lead['name'], 2);
        $result = $this->ac('POST', 'contact/sync', ['contact'=>[
            'email'=>$lead['email'], 'firstName'=>$parts[0], 'lastName'=>$parts[1] ?? '', 'phone'=>$lead['phone'],
        ]]);
        $id = (string)($result['contact']['id'] ?? '');
        if (!ctype_digit($id)) throw new DeliveryFailure('Contato sem ID na resposta.');
        $active = false;
        foreach ($this->allRelations($id, 'contactLists') as $row) {
            if ((string)$row['list'] !== $list) continue;
            // Não reinscreve quem saiu da lista ou foi bloqueado.
            if (in_array((string)$row['status'], ['2', '3'], true)) {
                throw new DeliveryFailure('Contato com restrição de inscrição.', true);
            }
            $active = (string)$row['status'] === '1';
        }
        if (!$active) $this->ac('POST', 'contactLists', ['contactList'=>['list'=>$list, 'contact'=>$id, 'status'=>1]]);
        foreach ($this->allRelations($id, 'contactTags') as $row) {
            if ((string)$row['tag'] === $tag) return;
        }
        $this->ac('POST', 'contactTags', ['contactTag'=>['contact'=>$id, 'tag'=>$tag]]);
    }

    public function sheets(array $survey): void
    {
        $url = (string)($this->config['sheets_webhook_url'] ?? '');
        if (!preg_match('~\Ahttps://script\.google\.com/macros/s/[A-Za-z0-9_-]+/exec\z~', $url) || empty($this->config['sheets_webhook_token'])) {
            throw new DeliveryFailure('Configure o webhook e o token da pesquisa.', true);
        }
        // ID fixo permite repetir após timeout sem acrescentar outra linha.
        $result = $this->request('POST', $url, [], [
            'token'=>$this->config['sheets_webhook_token'], 'submissionId'=>$survey['id'],
            'name'=>$survey['name'], 'email'=>$survey['email'], 'phone'=>$survey['phone'],
            'answers'=>json_decode($survey['answers'], true, 16, JSON_THROW_ON_ERROR),
        ], true);
        if (($result['ok'] ?? false) !== true) throw new DeliveryFailure('Planilha não confirmou o recebimento.');
    }
}
