<?php
require_once __DIR__ . '/require_login.php';
@date_default_timezone_set('America/Lima');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$raw = file_get_contents('php://input');
$in = $raw ? json_decode($raw, true) : [];
$dni = isset($in['dni']) ? trim($in['dni']) : '';
if ($dni === '' || !preg_match('/^\d{8}$/', $dni)) {
  echo json_encode(['ok'=>false,'error'=>'DNI inválido']); exit;
}

$reniecToken   = getenv('RENIEC_TOKEN');
$apiPeruToken  = getenv('APIPERU_TOKEN');
$decoToken     = getenv('DECOLECTA_TOKEN');
$cfg = @include __DIR__ . '/tools_tokens.php';
if (is_array($cfg)) {
  if (!$reniecToken   && !empty($cfg['RENIEC_TOKEN']))      $reniecToken = $cfg['RENIEC_TOKEN'];
  if (!$apiPeruToken  && !empty($cfg['APIPERU_TOKEN']))     $apiPeruToken = $cfg['APIPERU_TOKEN'];
  if (!$decoToken     && !empty($cfg['DECOLECTA_TOKEN']))   $decoToken = $cfg['DECOLECTA_TOKEN'];
}

// Construir lista de proveedores (orden: decolecta, apiperu, apis.net.pe)
$providers = [];
if ($decoToken) {
  // Doc oficial: https://api.decolecta.com/v1/reniec/dni?numero={DNI}
  // Auth: Header Authorization: Bearer <API_KEY>
  $providers[] = [ 'name'=>'api.decolecta.com', 'variant'=>'bearer', 'url'=>'https://api.decolecta.com/v1/reniec/dni?numero=' . urlencode($dni), 'headers'=>['Authorization: Bearer ' . $decoToken] ];
}
if ($apiPeruToken) {
  $providers[] = [ 'name'=>'apiperu.dev', 'url'=>'https://apiperu.dev/api/dni/' . urlencode($dni), 'headers'=>['Authorization: Bearer ' . $apiPeruToken] ];
}
if ($reniecToken) {
  $providers[] = [ 'name'=>'apis.net.pe', 'url'=>'https://api.apis.net.pe/v2/reniec/dni?numero=' . urlencode($dni), 'headers'=>['Authorization: Bearer ' . $reniecToken] ];
}

if (!$providers) {
  echo json_encode(['ok'=>false,'error'=>'Falta configurar tokens para DNI (DECOLECTA_TOKEN / APIPERU_TOKEN / RENIEC_TOKEN).']); exit;
}

$result = null; $last = '';
$attempts = [];
foreach ($providers as $p) {
  $ch = curl_init($p['url']);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 15);
  if (!empty($p['headers'])) curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(['Accept: application/json'], $p['headers']));
  else curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
  $body = curl_exec($ch);
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  $snippet = $body !== false ? substr($body,0,200) : $err;
  $attempts[] = [ 'provider'=>$p['name'], 'variant'=>$p['variant'] ?? '', 'http'=>$code, 'body'=> $snippet ];
  if ($err) { $last = 'cURL ' . $err; continue; }
  $j = json_decode($body, true);
  if ($code >= 200 && $code < 300 && is_array($j)) { $result = ['prov'=>$p['name'], 'variant'=>$p['variant'] ?? '', 'data'=>$j]; break; }
  $last = 'HTTP ' . $code . ' ' . $snippet;
}

if (!$result) {
  echo json_encode(['ok'=>false,'error'=>'No se pudo consultar RENIEC','detail'=>$last, 'attempts'=>$attempts], JSON_UNESCAPED_UNICODE); exit;
}

$norm = [
  'dni' => $dni,
  'nombres' => '',
  'apellidoPaterno' => '',
  'apellidoMaterno' => '',
  'nombreCompleto' => ''
];

$rp = $result['data'];
if ($result['prov'] === 'apis.net.pe' && isset($rp['nombres'])) {
  $norm['nombres'] = (string)$rp['nombres'];
  $norm['apellidoPaterno'] = (string)($rp['apellidoPaterno'] ?? '');
  $norm['apellidoMaterno'] = (string)($rp['apellidoMaterno'] ?? '');
  $norm['nombreCompleto'] = trim($norm['apellidoPaterno'].' '.$norm['apellidoMaterno'].' '.$norm['nombres']);
} elseif ($result['prov'] === 'apiperu.dev' && isset($rp['data'])) {
  $d = $rp['data'];
  $norm['nombres'] = (string)($d['nombres'] ?? '');
  $norm['apellidoPaterno'] = (string)($d['apellido_paterno'] ?? '');
  $norm['apellidoMaterno'] = (string)($d['apellido_materno'] ?? '');
  $norm['nombreCompleto'] = (string)($d['nombre_completo'] ?? trim($norm['apellidoPaterno'].' '.$norm['apellidoMaterno'].' '.$norm['nombres']));
} elseif ($result['prov'] === 'api.decolecta.com') {
  // Respuesta ejemplo docs DeColecta:
  // { first_name, first_last_name, second_last_name, full_name, document_number }
  $norm['nombres'] = (string)($rp['first_name'] ?? '');
  $norm['apellidoPaterno'] = (string)($rp['first_last_name'] ?? '');
  $norm['apellidoMaterno'] = (string)($rp['second_last_name'] ?? '');
  $norm['nombreCompleto'] = (string)($rp['full_name'] ?? trim($norm['apellidoPaterno'].' '.$norm['apellidoMaterno'].' '.$norm['nombres']));
  if (!empty($rp['document_number'])) $norm['dni'] = (string)$rp['document_number'];
}
echo json_encode(['ok'=>true,'source'=>$result['prov'],'auth_variant'=>$result['variant'],'data'=>$norm, 'attempts'=>$attempts], JSON_UNESCAPED_UNICODE);
