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

$perudevsToken     = getenv('PERUDEVS_TOKEN');
$perudevsRucUrl    = getenv('PERUDEVS_RUC_URL');
$perudevsHeader    = getenv('PERUDEVS_HEADER');
$perudevsRucMethod = getenv('PERUDEVS_RUC_METHOD');
$perudevsRucParam  = getenv('PERUDEVS_RUC_PARAM');
$cfg = @include __DIR__ . '/tools_tokens.php';
if (is_array($cfg)) {
  if (!$perudevsToken     && !empty($cfg['PERUDEVS_TOKEN']))      $perudevsToken = $cfg['PERUDEVS_TOKEN'];
  if (!$perudevsRucUrl    && !empty($cfg['PERUDEVS_RUC_URL']))    $perudevsRucUrl = $cfg['PERUDEVS_RUC_URL'];
  if (!$perudevsHeader    && !empty($cfg['PERUDEVS_HEADER']))     $perudevsHeader = $cfg['PERUDEVS_HEADER'];
  if (!$perudevsRucMethod && !empty($cfg['PERUDEVS_RUC_METHOD'])) $perudevsRucMethod = $cfg['PERUDEVS_RUC_METHOD'];
  if (!$perudevsRucParam  && !empty($cfg['PERUDEVS_RUC_PARAM']))  $perudevsRucParam = $cfg['PERUDEVS_RUC_PARAM'];
}

// Construir lista de proveedores (solo perudevs)
$providers = [];
if ($perudevsToken) {
  $paramKey = $perudevsRucParam ?: 'ruc';
  $keyParam = getenv('PERUDEVS_KEY_PARAM');
  if (!$keyParam && isset($cfg) && is_array($cfg) && !empty($cfg['PERUDEVS_KEY_PARAM'])) $keyParam = $cfg['PERUDEVS_KEY_PARAM'];
  if (!$keyParam) $keyParam = 'key';

  $method = $perudevsRucMethod ? strtoupper($perudevsRucMethod) : '';
  $url = $perudevsRucUrl ?: '';
  $body = null;

  if ($url === '') {
    $url = 'https://api.perudevs.com/api/v1/ruc';
    $method = $method ?: 'GET';
    $sep = (strpos($url, '?') === false) ? '?' : '&';
    $url .= $sep . rawurlencode($paramKey) . '=' . rawurlencode($ruc) . '&' . rawurlencode($keyParam) . '=' . rawurlencode($perudevsToken);
  } else if (preg_match('/\{ruc\}/i', $url)) {
    $url = preg_replace('/\{ruc\}/i', urlencode($ruc), $url);
    $method = $method ?: 'GET';
    $sep = (strpos($url, '?') === false) ? '?' : '&';
    $url .= $sep . rawurlencode($keyParam) . '=' . rawurlencode($perudevsToken);
  } else if (preg_match('/\{document\}/i', $url)) {
    $url = preg_replace('/\{document\}/i', urlencode($ruc), $url);
    $method = $method ?: 'GET';
    $sep = (strpos($url, '?') === false) ? '?' : '&';
    $url .= $sep . rawurlencode($keyParam) . '=' . rawurlencode($perudevsToken);
  } else {
    $method = $method ?: 'GET';
    $sep = (strpos($url, '?') === false) ? '?' : '&';
    $url = $url . $sep . rawurlencode($paramKey) . '=' . rawurlencode($ruc) . '&' . rawurlencode($keyParam) . '=' . rawurlencode($perudevsToken);
    if ($method === 'POST') { $body = json_encode([$paramKey => $ruc], JSON_UNESCAPED_UNICODE); }
  }

  $authLabel = 'query-key: ' . $keyParam;
  $prov = [ 'name'=>'api.perudevs.com', 'variant'=> ($method==='POST'?'post-json':'custom'), 'url'=>$url, 'headers'=>[], 'method'=>$method, 'auth_label'=>$authLabel ];
  if ($method === 'POST') { $prov['body'] = $body; $prov['headers'][] = 'Content-Type: application/json'; }
  $providers[] = $prov;
}

if (!$providers) {
  echo json_encode(['ok'=>false,'error'=>'Falta configurar token para RUC (PERUDEVS_TOKEN).']); exit;
}

$result = null; $last='';
$attempts = [];
foreach ($providers as $p) {
  $ch = curl_init($p['url']);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 15);
  if (!empty($p['method']) && strtoupper($p['method']) === 'POST') {
    curl_setopt($ch, CURLOPT_POST, true);
    if (isset($p['body'])) curl_setopt($ch, CURLOPT_POSTFIELDS, $p['body']);
  }
  if (!empty($p['headers'])) curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(['Accept: application/json'], $p['headers']));
  else curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
  $body = curl_exec($ch);
  $err = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  $snippet = $body !== false ? substr($body,0,200) : $err;
  $attempts[] = ['provider'=>$p['name'],'variant'=>$p['variant'] ?? '','http'=>$code,'method'=>($p['method'] ?? 'GET'),'auth'=>($p['auth_label'] ?? ''),'body'=>$snippet];
  if ($err){ $last = 'cURL ' . $err; continue; }
  $j = json_decode($body, true);
  if ($code >= 200 && $code < 300 && is_array($j)){ $result = ['prov'=>$p['name'],'variant'=>$p['variant'] ?? '','data'=>$j]; break; }
  $last = 'HTTP '.$code.' '.$snippet;
}

if (!$result){ echo json_encode(['ok'=>false,'error'=>'No se pudo consultar RUC','detail'=>$last,'attempts'=>$attempts], JSON_UNESCAPED_UNICODE); exit; }

$norm = [
  'ruc'=>$ruc,'razonSocial'=>'','estado'=>'','condicion'=>'','direccion'=>''
];

$rp = $result['data'];
if ($result['prov'] === 'api.perudevs.com') {
  // Normalización tentativa para perudevs.com (ajustar según respuesta real)
  // Si la API devuelve 'data', usarlo como raíz
  $root = isset($rp['data']) && is_array($rp['data']) ? $rp['data'] : $rp;
  $norm['razonSocial'] = (string)($root['razon_social'] ?? $root['nombre_o_razon_social'] ?? $root['nombre_comercial'] ?? $root['nombre'] ?? '');
  $norm['estado']      = (string)($root['estado'] ?? '');
  $norm['condicion']   = (string)($root['condicion'] ?? '');
  $norm['direccion']   = (string)($root['direccion'] ?? $root['domicilio_fiscal'] ?? '');
  if (!empty($root['ruc'])) $norm['ruc'] = (string)$root['ruc'];
  if (!empty($root['numero_ruc'])) $norm['ruc'] = (string)$root['numero_ruc'];
}
echo json_encode(['ok'=>true,'source'=>$result['prov'],'auth_variant'=>$result['variant'],'data'=>$norm,'attempts'=>$attempts], JSON_UNESCAPED_UNICODE);
