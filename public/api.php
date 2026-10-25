<?php
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
session_set_cookie_params(['httponly'=>true,'secure'=>(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off'),'samesite'=>'Lax','path'=>'/']);
ini_set('session.use_strict_mode', '1');
session_start();
$config = require dirname(__DIR__).'/private/config.php';
require_once dirname(__DIR__).'/private/storage.php';
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
function respond(int $status, array $data): never { http_response_code($status); echo json_encode($data,JSON_UNESCAPED_UNICODE); exit; }
try{
 $action=$_GET['action']??'';
 if($action==='init'&&$_SERVER['REQUEST_METHOD']==='GET'){
  $db = database();
  $lead = null;
  $done = false;
  if (!empty($_SESSION['lead_id'])) {
   $query = $db->prepare('SELECT id, email FROM leads WHERE id = ?');
   $query->execute([$_SESSION['lead_id']]);
   $lead = $query->fetch() ?: null;
   if ($lead) {
    $query = $db->prepare('SELECT id FROM surveys WHERE lead_id = ?');
    $query->execute([$lead['id']]);
    $done = (bool)$query->fetch();
   }
  }
  $wa = trim((string)($config['whatsapp_url'] ?? ''));
  if (!preg_match('~\Ahttps://(?:chat\.whatsapp\.com/[A-Za-z0-9/?=&_-]+|sndflw\.com/i/[A-Za-z0-9_-]+)\z~', $wa)) $wa = '';
  respond(200, ['csrf'=>$_SESSION['csrf'], 'registered'=>(bool)$lead,
   'email'=>$lead['email'] ?? '', 'survey_done'=>$done, 'whatsapp'=>$lead ? $wa : '']);
 }
 if($_SERVER['REQUEST_METHOD']!=='POST')respond(405,['message'=>'Método não permitido.']);
 if(!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??''))respond(403,['message'=>'Sua sessão expirou. Recarregue a página.']);
 if((int)($_SERVER['CONTENT_LENGTH']??0)>16384)respond(413,['message'=>'Envio muito grande.']);
 $raw=file_get_contents('php://input',false,null,0,16385);if(strlen($raw)>16384)respond(413,['message'=>'Envio muito grande.']);
 $input=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(!is_array($input))respond(422,['message'=>'Dados inválidos.']);
 if($action==='signup'){
  if(!empty($input['website']))respond(422,['message'=>'Cadastro inválido.']);
  $name=trim((string)($input['name']??''));$email=trim((string)($input['email']??''));$ddi=(string)($input['ddi']??'55');$number=preg_replace('/\D/','',(string)($input['phone']??''));
  if(strlen($name)<2||strlen($name)>160||strlen($email)>254||!filter_var($email,FILTER_VALIDATE_EMAIL)||!in_array($ddi,array_column(json_decode(file_get_contents(__DIR__.'/assets/countries.json'),true,512,JSON_THROW_ON_ERROR),'ddi'),true)||strlen($number)<4||strlen($number)+strlen($ddi)>15||($ddi==='55'&&!in_array(strlen($number),[10,11],true)))respond(422,['message'=>'Confira seu nome, e-mail e telefone com DDD.']);
  $utms = [];
  $submittedUtms = is_array($input['utms'] ?? null) ? $input['utms'] : [];
  foreach (['utm_source','utm_medium','utm_campaign','utm_content','utm_term'] as $key) {
   $value = $submittedUtms[$key] ?? '';
   if (!is_string($value) || strlen($value) > 2000) respond(422,['message'=>'Parâmetros de campanha inválidos.']);
   $utms[$key] = trim($value);
  }
  $phone = '+'.$ddi.$number;
  $fingerprint = hash('sha256', json_encode([$name, $email, $phone], JSON_THROW_ON_ERROR));
  $sameAttempt = ($_SESSION['signup_fingerprint'] ?? '') === $fingerprint;
  if (!$sameAttempt && time()-($_SESSION['last_attempt'] ?? 0)<3) respond(429,['message'=>'Aguarde alguns segundos e tente novamente.']);
  $_SESSION['last_attempt'] = time();
  if (!$sameAttempt) {
   $_SESSION['signup_id'] = bin2hex(random_bytes(24));
   $_SESSION['signup_fingerprint'] = $fingerprint;
  }
  // Persiste a chave de repetição na sessão antes de tentar gravar no banco.
  $id = $_SESSION['signup_id'];
  session_write_close();
  receiveSignup(database(), $id, $name, $email, $phone, $utms);
  session_start();
  $_SESSION['lead_id'] = $id;
  unset($_SESSION['contact'], $_SESSION['survey_done']);
  respond(202,['ok'=>true, 'redirect'=>'obrigado.html']);
 }
 if($action==='survey'){
  if(empty($_SESSION['lead_id']))respond(401,['message'=>'Faça sua inscrição antes de responder.']);
  $answers=$input['answers']??null;
  if(!is_array($answers)||count($answers)!==10||!array_is_list($answers))respond(422,['message'=>'Responda às 10 perguntas.']);
  foreach($answers as $answer){if(!is_string($answer)||!trim($answer)||strlen($answer)>500)respond(422,['message'=>'Confira suas respostas.']);}
  $ageBands = ['Menos de 18 anos','De 18 a 25 anos','De 26 a 40 anos','De 41 a 45 anos','De 46 a 60 anos','61 anos ou mais'];
  $validAge = in_array($answers[2], $ageBands, true) || (ctype_digit($answers[2]) && (int)$answers[2]>=1 && (int)$answers[2]<=120);
  if(!filter_var($answers[0],FILTER_VALIDATE_EMAIL)||!$validAge)respond(422,['message'=>'Confira seu e-mail e idade.']);
  $leadId = $_SESSION['lead_id'];
  session_write_close();
  receiveSurvey(database(), $leadId, $answers);
  respond(202,['ok'=>true]);
 }
 respond(404,['message'=>'Operação não encontrada.']);
}catch(JsonException $e){respond(400,['message'=>'Dados inválidos.']);}catch(Throwable $e){error_log('3 Liberdades: falha ao receber cadastro/pesquisa ('.get_class($e).').');respond(503,['message'=>'Não foi possível salvar agora. Seus dados continuam na tela; tente novamente em instantes.']);}

