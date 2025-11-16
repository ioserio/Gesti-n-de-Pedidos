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

$perudevsToken     = getenv('PERUDEVS_TOKEN');
$perudevsDniUrl    = getenv('PERUDEVS_DNI_URL');
$perudevsHeader    = getenv('PERUDEVS_HEADER');
$perudevsDniMethod = getenv('PERUDEVS_DNI_METHOD');
$perudevsDniParam  = getenv('PERUDEVS_DNI_PARAM');
$cfg = @include __DIR__ . '/tools_tokens.php';
if (is_array($cfg)) {
  if (!$perudevsToken     && !empty($cfg['PERUDEVS_TOKEN']))      $perudevsToken = $cfg['PERUDEVS_TOKEN'];
  if (!$perudevsDniUrl    && !empty($cfg['PERUDEVS_DNI_URL']))    $perudevsDniUrl = $cfg['PERUDEVS_DNI_URL'];
  if (!$perudevsHeader    && !empty($cfg['PERUDEVS_HEADER']))     $perudevsHeader = $cfg['PERUDEVS_HEADER'];
  if (!$perudevsDniMethod && !empty($cfg['PERUDEVS_DNI_METHOD'])) $perudevsDniMethod = $cfg['PERUDEVS_DNI_METHOD'];
  if (!$perudevsDniParam  && !empty($cfg['PERUDEVS_DNI_PARAM']))  $perudevsDniParam = $cfg['PERUDEVS_DNI_PARAM'];
}

// Construir lista de proveedores (solo perudevs)
$providers = [];
if ($perudevsToken) {
  // Construcción flexible de perudevs (POST/GET y param configurable)
  $paramKey = $perudevsDniParam ?: 'document';
  $keyParam = getenv('PERUDEVS_KEY_PARAM');
  if (!$keyParam && isset($cfg) && is_array($cfg) && !empty($cfg['PERUDEVS_KEY_PARAM'])) $keyParam = $cfg['PERUDEVS_KEY_PARAM'];
  if (!$keyParam) $keyParam = 'key';

  $method = $perudevsDniMethod ? strtoupper($perudevsDniMethod) : '';
  $url = $perudevsDniUrl ?: '';
  $body = null;

  if ($url === '') {
    // Fallback: endpoint por path
    $url = 'https://api.perudevs.com/api/v1/dni/complete';
    $method = 'GET';
    // Query: document y key
    $sep = (strpos($url, '?') === false) ? '?' : '&';
    $url .= $sep . rawurlencode($paramKey) . '=' . rawurlencode($dni) . '&' . rawurlencode($keyParam) . '=' . rawurlencode($perudevsToken);
  } else if (preg_match('/\{dni\}/i', $url)) {
    $url = preg_replace('/\{dni\}/i', urlencode($dni), $url);
    $method = $method ?: 'GET';
    $sep = (strpos($url, '?') === false) ? '?' : '&';
    $url .= $sep . rawurlencode($keyParam) . '=' . rawurlencode($perudevsToken);
  } else if (preg_match('/\{document\}/i', $url)) {
    $url = preg_replace('/\{document\}/i', urlencode($dni), $url);
    $method = $method ?: 'GET';
    $sep = (strpos($url, '?') === false) ? '?' : '&';
    $url .= $sep . rawurlencode($keyParam) . '=' . rawurlencode($perudevsToken);
  } else {
    // URL sin placeholder => por defecto POST JSON con campo configurable
    $method = $method ?: 'GET';
    $sep = (strpos($url, '?') === false) ? '?' : '&';
    $url = $url . $sep . rawurlencode($paramKey) . '=' . rawurlencode($dni) . '&' . rawurlencode($keyParam) . '=' . rawurlencode($perudevsToken);
    if ($method === 'POST') { $body = json_encode([$paramKey => $dni], JSON_UNESCAPED_UNICODE); }
  }

  // Para diagnóstico: indicar que usamos key en query
  $authLabel = 'query-key: ' . $keyParam;

  $prov = [ 'name'=>'api.perudevs.com', 'variant'=> ($method==='POST'?'post-json':'custom'), 'url'=>$url, 'headers'=>[], 'method'=>$method, 'auth_label'=>$authLabel ];
  if ($method === 'POST') { $prov['body'] = $body; $prov['headers'][] = 'Content-Type: application/json'; }
  $providers[] = $prov;
}

if (!$providers) {
  echo json_encode(['ok'=>false,'error'=>'Falta configurar token para DNI (PERUDEVS_TOKEN).']); exit;
}

$result = null; $last = '';
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
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  $snippet = $body !== false ? substr($body,0,200) : $err;
  $attempts[] = [ 'provider'=>$p['name'], 'variant'=>$p['variant'] ?? '', 'http'=>$code, 'method'=>($p['method'] ?? 'GET'), 'auth'=>($p['auth_label'] ?? ''), 'body'=> $snippet ];
  if ($err) { $last = 'cURL ' . $err; continue; }
  $j = json_decode($body, true);
  if ($code >= 200 && $code < 300 && is_array($j)) { $result = ['prov'=>$p['name'], 'variant'=>$p['variant'] ?? '', 'data'=>$j]; break; }
  $last = 'HTTP ' . $code . ' ' . $snippet;
}

if (!$result) {
  echo json_encode(['ok'=>false,'error'=>'No se pudo consultar DNI','detail'=>$last, 'attempts'=>$attempts], JSON_UNESCAPED_UNICODE); exit;
}

$norm = [
  'dni' => $dni,
  'nombres' => '',
  'apellidoPaterno' => '',
  'apellidoMaterno' => '',
  'nombreCompleto' => ''
];

$rp = $result['data'];
 if ($result['prov'] === 'api.perudevs.com') {
   // Normalización amplia para perudevs.com (maneja diferentes envolturas y claves)
   $root = $rp;
   if (isset($rp['data']) && is_array($rp['data'])) $root = $rp['data'];
   elseif (isset($rp['result']) && is_array($rp['result'])) $root = $rp['result'];
   elseif (isset($rp['resultado']) && is_array($rp['resultado'])) $root = $rp['resultado'];
   elseif (isset($rp['response']) && is_array($rp['response'])) $root = $rp['response'];
   elseif (isset($rp['payload']) && is_array($rp['payload'])) $root = $rp['payload'];

   // Acceso case-insensitive
   $L = [];
   if (is_array($root)) { foreach ($root as $k=>$v) { $L[strtolower($k)] = $v; } }
   $get = function(array $cands) use ($L) {
     foreach ($cands as $ck) { $lk = strtolower($ck); if (array_key_exists($lk, $L) && $L[$lk] !== null && $L[$lk] !== '') return $L[$lk]; }
     return null;
   };

   $nombres = $get(['nombres','nombre','first_name','primer_nombre']);
   $apep    = $get(['apellido_paterno','apellidopaterno','paterno','first_last_name']);
   $apem    = $get(['apellido_materno','apellidomaterno','materno','second_last_name']);
   $full    = $get(['nombre_completo','nombrecompleto','full_name','fullname']);
  $doc     = $get(['dni','id','document','documento','numero','numero_documento','document_number']);

  // Extras comunes reportados por perudevs
  $genero  = $get(['genero','sexo']);
  $fnac    = $get(['fecha_nacimiento','fechanacimiento','birthdate','fecha_de_nacimiento']);
  $codver  = $get(['codigo_verificacion','codigoverificacion','verifier','verification_code']);

   if ($doc) $norm['dni'] = (string)$doc;
   if ($nombres) $norm['nombres'] = (string)$nombres;
   if ($apep) $norm['apellidoPaterno'] = (string)$apep;
   if ($apem) $norm['apellidoMaterno'] = (string)$apem;
  $norm['nombreCompleto'] = (string)($full ?? trim(($norm['apellidoPaterno']??'').' '.($norm['apellidoMaterno']??'').' '.($norm['nombres']??'')));
  if ($genero) $norm['genero'] = (string)$genero;
  if ($fnac) $norm['fechaNacimiento'] = (string)$fnac;
  if ($codver) $norm['codigoVerificacion'] = (string)$codver;
}
echo json_encode(['ok'=>true,'source'=>$result['prov'],'auth_variant'=>$result['variant'],'data'=>$norm, 'attempts'=>$attempts], JSON_UNESCAPED_UNICODE);
