<?php
require_once __DIR__ . '/require_login.php';
@date_default_timezone_set('America/Lima');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$raw = file_get_contents('php://input');
$in = $raw ? json_decode($raw, true) : [];
$ruc = isset($in['ruc']) ? trim($in['ruc']) : '';
if ($ruc === '' || !preg_match('/^\d{11}$/', $ruc)) {
  echo json_encode(['ok'=>false,'error'=>'RUC inválido']); exit;
}

$sunatToken    = getenv('SUNAT_TOKEN');
$apiPeruToken  = getenv('APIPERU_TOKEN');
$decoToken     = getenv('DECOLECTA_TOKEN');
$cfg = @include __DIR__ . '/tools_tokens.php';
if (is_array($cfg)) {
  if (!$sunatToken   && !empty($cfg['SUNAT_TOKEN']))      $sunatToken = $cfg['SUNAT_TOKEN'];
  if (!$apiPeruToken && !empty($cfg['APIPERU_TOKEN']))    $apiPeruToken = $cfg['APIPERU_TOKEN'];
  if (!$decoToken    && !empty($cfg['DECOLECTA_TOKEN']))  $decoToken = $cfg['DECOLECTA_TOKEN'];
}

// Construir lista de proveedores (orden: decolecta, apiperu, apis.net.pe)
$providers = [];
if ($decoToken) {
  // Suposición basada en patrón docs: https://api.decolecta.com/v1/sunat/ruc?numero={RUC}
  // Auth: Authorization: Bearer <API_KEY>
  $providers[] = [ 'name'=>'api.decolecta.com','variant'=>'bearer','url'=>'https://api.decolecta.com/v1/sunat/ruc?numero=' . urlencode($ruc),'headers'=>['Authorization: Bearer ' . $decoToken] ];
}
if ($apiPeruToken) {
  $providers[] = [ 'name'=>'apiperu.dev', 'url'=>'https://apiperu.dev/api/ruc/' . urlencode($ruc), 'headers'=>['Authorization: Bearer '.$apiPeruToken] ];
}
if ($sunatToken) {
  $providers[] = [ 'name'=>'apis.net.pe', 'url'=>'https://api.apis.net.pe/v2/sunat/ruc?numero=' . urlencode($ruc), 'headers'=>['Authorization: Bearer '.$sunatToken] ];
}

if (!$providers) {
  echo json_encode(['ok'=>false,'error'=>'Falta configurar tokens para RUC (DECOLECTA_TOKEN / APIPERU_TOKEN / SUNAT_TOKEN).']); exit;
}

$result = null; $last='';
$attempts = [];
foreach ($providers as $p) {
  $ch = curl_init($p['url']);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 15);
  if (!empty($p['headers'])) curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(['Accept: application/json'], $p['headers']));
  else curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
  $body = curl_exec($ch);
  $err = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  $snippet = $body !== false ? substr($body,0,200) : $err;
  $attempts[] = ['provider'=>$p['name'],'variant'=>$p['variant'] ?? '','http'=>$code,'body'=>$snippet];
  if ($err){ $last = 'cURL ' . $err; continue; }
  $j = json_decode($body, true);
  if ($code >= 200 && $code < 300 && is_array($j)){ $result = ['prov'=>$p['name'],'variant'=>$p['variant'] ?? '','data'=>$j]; break; }
  $last = 'HTTP '.$code.' '.$snippet;
}

if (!$result){ echo json_encode(['ok'=>false,'error'=>'No se pudo consultar SUNAT','detail'=>$last,'attempts'=>$attempts], JSON_UNESCAPED_UNICODE); exit; }

$norm = [
  'ruc'=>$ruc,'razonSocial'=>'','estado'=>'','condicion'=>'','direccion'=>''
];

$rp = $result['data'];
if ($result['prov'] === 'apis.net.pe' && isset($rp['nombre'])) {
  $norm['razonSocial'] = (string)$rp['nombre'];
  $norm['estado']      = (string)($rp['estado'] ?? '');
  $norm['condicion']   = (string)($rp['condicion'] ?? '');
  $norm['direccion']   = (string)($rp['direccion'] ?? '');
} elseif ($result['prov'] === 'apiperu.dev' && isset($rp['data'])) {
  $d = $rp['data'];
  $norm['razonSocial'] = (string)($d['nombre_o_razon_social'] ?? '');
  $norm['estado']      = (string)($d['estado'] ?? '');
  $norm['condicion']   = (string)($d['condicion'] ?? '');
  $norm['direccion']   = (string)($d['direccion'] ?? '');
} elseif ($result['prov'] === 'api.decolecta.com') {
  // Normalización tentativa (ajustar según documentación real SUNAT en Decolecta)
  // Campos esperados posibles: razon_social, nombre_comercial, estado, condicion, direccion, numero_ruc
  $norm['razonSocial'] = (string)($rp['razon_social'] ?? $rp['nombre_comercial'] ?? $rp['nombre'] ?? '');
  $norm['estado']      = (string)($rp['estado'] ?? '');
  $norm['condicion']   = (string)($rp['condicion'] ?? '');
  $norm['direccion']   = (string)($rp['direccion'] ?? $rp['domicilio_fiscal'] ?? '');
  if (!empty($rp['numero_ruc'])) $norm['ruc'] = (string)$rp['numero_ruc'];
}
echo json_encode(['ok'=>true,'source'=>$result['prov'],'auth_variant'=>$result['variant'],'data'=>$norm,'attempts'=>$attempts], JSON_UNESCAPED_UNICODE);
