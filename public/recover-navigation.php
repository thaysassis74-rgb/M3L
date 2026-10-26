<?php
declare(strict_types=1);
ini_set('display_errors', '0');
header('Cache-Control: no-store, private');
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
// Apenas reabre a página com um rascunho: não grava leads, não chama integrações.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$pages = [
    '/'=>'index.html', '/index.html'=>'index.html',
    '/v2'=>'v2/index.html', '/v2/'=>'v2/index.html', '/v2/index.html'=>'v2/index.html',
    '/v3'=>'v3/index.html', '/v3/'=>'v3/index.html', '/v3/index.html'=>'v3/index.html',
];
if (!is_string($path) || !isset($pages[$path])) { http_response_code(404); exit; }
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>16384) { http_response_code(413); exit; }
$input = $_POST;
if (str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    $raw=file_get_contents('php://input', false, null, 0, 16385);
    if ($raw===false || strlen($raw)>16384) { http_response_code(413); exit; }
    $decoded=json_decode($raw,true,16);
    $input=is_array($decoded) ? $decoded : [];
}
$draft=[];
foreach (['name'=>160,'email'=>254,'phone'=>24,'ddi'=>4,'website'=>500] as $key=>$limit) {
    if (isset($input[$key]) && is_string($input[$key])) $draft[$key]=mbSafeSlice($input[$key],$limit);
}
function mbSafeSlice(string $value,int $limit): string {
    // Limites em bytes são suficientes para rascunho; JSON substitui UTF-8 truncado.
    return substr($value,0,$limit);
}
$html=file_get_contents(__DIR__.'/'.$pages[$path]);
if ($html===false) { http_response_code(503); exit; }
$json=json_encode(['fields'=>$draft],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
$bootstrap='<script type="application/json" id="m3l-navigation-draft">'.$json.'</script>';
$html=str_replace('</head>',$bootstrap.'</head>',$html);
http_response_code(200);
echo $html;
