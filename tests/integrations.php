<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/private/integrations.php';
function check(bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException('FAIL: '.$label);
    echo 'OK: '.$label.PHP_EOL;
}
$config = [
    'api_url'=>'https://example.api-us1.com', 'api_key'=>'dummy-test-key',
    'list_id'=>'6', 'tag_id'=>'1',
    'sheets_webhook_url'=>'https://script.google.com/macros/s/dummy/exec',
    'sheets_webhook_token'=>'dummy-test-token',
];
$lead = ['id'=>str_repeat('a',48), 'name'=>'Teste Local', 'email'=>'teste@example.com', 'phone'=>'+5511999999999'];
$active = false; $tagged = false; $listWrites = 0; $tagWrites = 0; $timeout = true;
$transport = static function ($method, $url, $headers, $payload) use (&$active, &$tagged, &$listWrites, &$tagWrites, &$timeout): array {
    $path = parse_url($url, PHP_URL_PATH);
    if (str_ends_with($path, '/contact/sync')) return ['contact'=>['id'=>'42']];
    if ($method === 'GET' && str_ends_with($path, '/contactLists')) return ['contactLists'=>$active ? [['list'=>'6','status'=>'1']] : []];
    if ($method === 'GET' && str_ends_with($path, '/contactTags')) return ['contactTags'=>$tagged ? [['tag'=>'1']] : []];
    if ($method === 'POST' && str_ends_with($path, '/contactLists')) {
        $active = true; $listWrites++; return [];
    }
    if ($method === 'POST' && str_ends_with($path, '/contactTags')) {
        $tagged = true; $tagWrites++;
        if ($timeout) { $timeout = false; throw new DeliveryFailure('Timeout depois de gravar.'); }
        return [];
    }
    throw new RuntimeException('Chamada inesperada no mock.');
};
$client = new Integrations($config, $transport);
try { $client->activecampaign($lead); throw new LogicException('Deveria falhar.'); }
catch (DeliveryFailure $error) { check(!$error->permanent, 'timeout preserva possibilidade de repetição'); }
$client->activecampaign($lead);
check($listWrites === 1 && $tagWrites === 1, 'repetição após timeout não repete lista/tag existentes');
$blockedWrites = 0;
$blocked = new Integrations($config, static function ($method, $url, $headers, $payload) use (&$blockedWrites): array {
    if (str_contains($url, 'contact/sync')) return ['contact'=>['id'=>'42']];
    if ($method === 'GET') return ['contactLists'=>[['list'=>'6','status'=>'2']]];
    $blockedWrites++; return [];
});
try { $blocked->activecampaign($lead); throw new LogicException('Deveria bloquear.'); }
catch (DeliveryFailure $error) { check($error->permanent && $blockedWrites === 0, 'não reinscreve contato que saiu da lista'); }
$ids = [];
$sheets = new Integrations($config, static function ($method, $url, $headers, $payload) use (&$ids): array {
    $ids[] = $payload['submissionId']; return ['ok'=>true];
});
$survey = $lead + ['answers'=>json_encode(array_fill(0,10,'Resposta'))];
$sheets->sheets($survey); $sheets->sheets($survey);
check(count(array_unique($ids)) === 1 && strlen($ids[0]) === 48, 'pesquisa mantém ID idempotente aceito pelo Apps Script');
$negative = new Integrations($config, static fn()=>['ok'=>false]);
try { $negative->sheets($survey); throw new LogicException('Deveria falhar.'); }
catch (DeliveryFailure $error) { check(true, 'não confirma entrega quando a planilha responde ok=false'); }
echo "Testes das integrações concluídos sem chamadas externas.\n";
