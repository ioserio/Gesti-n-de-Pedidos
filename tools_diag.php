<?php
require_once __DIR__ . '/require_login.php';
@date_default_timezone_set('America/Lima');
header('Content-Type: text/plain; charset=utf-8');

$cfg = @include __DIR__ . '/tools_tokens.php';
$reniecToken = getenv('RENIEC_TOKEN') ?: ($cfg['RENIEC_TOKEN'] ?? '');
$sunatToken  = getenv('SUNAT_TOKEN')  ?: ($cfg['SUNAT_TOKEN'] ?? '');
$apiperuToken= getenv('APIPERU_TOKEN') ?: ($cfg['APIPERU_TOKEN'] ?? '');

echo "Detectando tokens (longitud mostrada, no valor completo)\n";
$showLen = function($t){ if(!$t) return 'NO'; return 'LEN=' . strlen($t); };
echo 'RENIEC_TOKEN: ' . $showLen($reniecToken) . "\n";
echo 'SUNAT_TOKEN:  ' . $showLen($sunatToken) . "\n";
echo 'APIPERU_TOKEN:' . $showLen($apiperuToken) . "\n\n";

function testCall($label, $url, $headers){
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(['Accept: application/json'], $headers));
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "== $label ==\n";
    if($err){ echo "cURL error: $err\n"; return; }
    echo "HTTP: $code\n";
    echo "Body (first 300): " . substr($body,0,300) . "\n\n";
}

// Ejemplos de prueba con DNI fijo de demostración (usar un DNI válido real para ver datos)
$dniDemo = '00000000';
$rucDemo = '00000000000';

if($reniecToken){ testCall('RENIEC via apis.net.pe', 'https://api.apis.net.pe/v2/reniec/dni?numero=' . $dniDemo, ['Authorization: Bearer ' . $reniecToken]); }
if($sunatToken){ testCall('SUNAT via apis.net.pe', 'https://api.apis.net.pe/v2/sunat/ruc?numero=' . $rucDemo, ['Authorization: Bearer ' . $sunatToken]); }
if($apiperuToken){
    testCall('DNI via apiperu.dev', 'https://apiperu.dev/api/dni/' . $dniDemo, ['Authorization: Bearer ' . $apiperuToken]);
    testCall('RUC via apiperu.dev', 'https://apiperu.dev/api/ruc/' . $rucDemo, ['Authorization: Bearer ' . $apiperuToken]);
}

echo "Fin diagnóstico. Reemplace los DNI/RUC de prueba por valores válidos para ver respuestas reales.\n";
