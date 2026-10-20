<?php
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
session_set_cookie_params(['httponly'=>true,'secure'=>(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off'),'samesite'=>'Lax','path'=>'/']);
session_start();
$config = require dirname(__DIR__).'/private/config.php';
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
function respond(int $status, array $data): never { http_response_code($status); echo json_encode($data,JSON_UNESCAPED_UNICODE); exit; }
function ac(string $method,string $path,?array $payload=null): array {
 global $config;
 if (!$config['api_key']) throw new RuntimeException('API não configurada.');
 $ch=curl_init(rtrim($config['api_url'],'/').'/api/3/'.$path);
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,CURLOPT_HTTPHEADER=>['Api-Token: '.$config['api_key'],'Content-Type: application/json']]);
 if($payload!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
 $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
 if($body===false||$status<200||$status>=300)throw new RuntimeException('ActiveCampaign HTTP '.$status.' ('.$method.' '.explode('?',$path)[0].')');
 return json_decode($body,true,512,JSON_THROW_ON_ERROR);
}
function findId(string $resource,string $field,string $name): string {
 for($offset=0;$offset<10000;$offset+=100){$data=ac('GET',$resource.'?limit=100&offset='.$offset);foreach($data[$resource]??[] as $row){if(($row[$field]??'')===$name)return (string)$row['id'];}if(count($data[$resource]??[])<100)break;}
 throw new RuntimeException('Não encontrado: '.$resource);
}
try{
 $action=$_GET['action']??'';
 if($action==='init'&&$_SERVER['REQUEST_METHOD']==='GET'){
  $wa=$config['whatsapp_url'];if($wa&&!preg_match('~^https://chat\.whatsapp\.com/[A-Za-z0-9/?=&_-]+$~',$wa))$wa='';
  respond(200,['csrf'=>$_SESSION['csrf'],'registered'=>isset($_SESSION['contact']),'email'=>$_SESSION['email']??'','survey_done'=>$_SESSION['survey_done']??false,'whatsapp'=>isset($_SESSION['contact'])?$wa:'']);
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
  // Limite por sessão. Adicione limitação por IP na hospedagem para tráfego público.
  if(time()-($_SESSION['last_attempt']??0)<3)respond(429,['message'=>'Aguarde alguns segundos e tente novamente.']);$_SESSION['last_attempt']=time();
  $list=findId('lists','name',$config['list_name']);$tag=findId('tags','tag',$config['tag_name']);
  $parts=explode(' ',$name,2);
  $result=ac('POST','contact/sync',['contact'=>['email'=>$email,'firstName'=>$parts[0],'lastName'=>$parts[1]??'','phone'=>'+'.$ddi.$number]]);$id=(string)$result['contact']['id'];
  $memberships=ac('GET','contacts/'.$id.'/contactLists');$active=false;
  foreach($memberships['contactLists']??[] as $membership){if((string)$membership['list']===$list){$active=(string)$membership['status']==='1';if(in_array((string)$membership['status'],['2','3'],true))respond(409,['message'=>'Este e-mail tem uma restrição de inscrição. Entre em contato com a equipe.']);}}
  if(!$active)ac('POST','contactLists',['contactList'=>['list'=>$list,'contact'=>$id,'status'=>1]]);
  $tags=ac('GET','contacts/'.$id.'/contactTags');$hasTag=false;foreach($tags['contactTags']??[] as $item){if((string)$item['tag']===$tag)$hasTag=true;}
  if(!$hasTag)ac('POST','contactTags',['contactTag'=>['contact'=>$id,'tag'=>$tag]]);
  if(($_SESSION['contact']??'')!==$id)unset($_SESSION['survey_note'],$_SESSION['survey_done'],$_SESSION['survey_submission']);
  session_regenerate_id(true);$_SESSION['contact']=$id;$_SESSION['email']=$email;$_SESSION['name']=$name;$_SESSION['phone']='+'.$ddi.$number;
  respond(200,['ok'=>true]);
 }
 if($action==='survey'){
  if(empty($_SESSION['contact']))respond(401,['message'=>'Faça sua inscrição antes de responder.']);
  $answers=$input['answers']??null;
  if(!is_array($answers)||count($answers)!==10||!array_is_list($answers))respond(422,['message'=>'Responda às 10 perguntas.']);
  foreach($answers as $answer){if(!is_string($answer)||!trim($answer)||strlen($answer)>500)respond(422,['message'=>'Confira suas respostas.']);}
  if(!filter_var($answers[0],FILTER_VALIDATE_EMAIL)||!ctype_digit($answers[2])||(int)$answers[2]<1||(int)$answers[2]>120)respond(422,['message'=>'Confira seu e-mail e idade.']);
  $labels=['Melhor e-mail','Sexo','Idade','Escolaridade','Há quanto tempo acompanha','Já investe','Conhecimento do mercado financeiro','Profissão','Renda mensal','Já é aluno'];
  if(empty($config['sheets_webhook_url'])||empty($config['sheets_webhook_token']))throw new RuntimeException('Configure SHEETS_WEBHOOK_URL e SHEETS_WEBHOOK_TOKEN.');
  if(!preg_match('~^https://script\.google\.com/macros/s/[A-Za-z0-9_-]+/exec$~',$config['sheets_webhook_url']))throw new RuntimeException('URL do Apps Script inválida.');
  $_SESSION['survey_submission'] ??= bin2hex(random_bytes(24));
  $payload=['token'=>$config['sheets_webhook_token'],'submissionId'=>$_SESSION['survey_submission'],'name'=>$_SESSION['name']??'','email'=>$_SESSION['email'],'phone'=>$_SESSION['phone']??'','answers'=>$answers];
  $ch=curl_init($config['sheets_webhook_url']);
  curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>35,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS]);
  $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$errno=curl_errno($ch);curl_close($ch);
  if($body===false||$status!==200)throw new RuntimeException('Google Sheets HTTP '.$status.'; cURL '.$errno);
  $result=json_decode($body,true,512,JSON_THROW_ON_ERROR);
  if(($result['ok']??false)!==true)throw new RuntimeException('Pesquisa não confirmada pela planilha.');
  $_SESSION['survey_done']=true;respond(200,['ok'=>true]);
 }
 respond(404,['message'=>'Operação não encontrada.']);
}catch(JsonException $e){respond(400,['message'=>'Dados inválidos.']);}catch(Throwable $e){error_log('3 Liberdades: '.$e->getMessage());respond(503,['message'=>'Não foi possível salvar agora. Seus dados continuam na tela; tente novamente em instantes.']);}
