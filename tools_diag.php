<?php
require_once __DIR__ . '/require_login.php';
@date_default_timezone_set('America/Lima');
header('Content-Type: text/plain; charset=utf-8');

$cfg = @include __DIR__ . '/tools_tokens.php';
$perudevsToken   = getenv('PERUDEVS_TOKEN')    ?: ($cfg['PERUDEVS_TOKEN'] ?? '');
$perudevsHeader  = getenv('PERUDEVS_HEADER')   ?: ($cfg['PERUDEVS_HEADER'] ?? '');
$perudevsKeyPar  = getenv('PERUDEVS_KEY_PARAM')?: ($cfg['PERUDEVS_KEY_PARAM'] ?? 'key');
$perudevsDniUrl  = getenv('PERUDEVS_DNI_URL')  ?: ($cfg['PERUDEVS_DNI_URL'] ?? '');
$perudevsRucUrl  = getenv('PERUDEVS_RUC_URL')  ?: ($cfg['PERUDEVS_RUC_URL'] ?? '');
$perudevsDniMet  = getenv('PERUDEVS_DNI_METHOD') ?: ($cfg['PERUDEVS_DNI_METHOD'] ?? 'POST');
$perudevsDniPar  = getenv('PERUDEVS_DNI_PARAM')  ?: ($cfg['PERUDEVS_DNI_PARAM'] ?? 'document');
$perudevsRucMet  = getenv('PERUDEVS_RUC_METHOD') ?: ($cfg['PERUDEVS_RUC_METHOD'] ?? 'POST');
$perudevsRucPar  = getenv('PERUDEVS_RUC_PARAM')  ?: ($cfg['PERUDEVS_RUC_PARAM'] ?? 'ruc');

echo "Detectando token perudevs (longitud mostrada, no valor completo)\n";
$showLen = function($t){ if(!$t) return 'NO'; return 'LEN=' . strlen($t); };
echo 'PERUDEVS_TOKEN: ' . $showLen($perudevsToken) . "\n\n";

function testCall($label, $url, $headers, $method='GET', $body=null){
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $headers = array_merge(['Accept: application/json','Content-Type: application/json'], $headers);
    } else {
        $headers = array_merge(['Accept: application/json'], $headers);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "== $label ==\n";
    if($err){ echo "cURL error: $err\n"; return; }
    echo "HTTP: $code\n";
    echo "Body (first 300): " . substr($body,0,300) . "\n\n";
}

// Ejemplos de prueba con DNI/RUC fijos de demostración (usar válidos para ver datos reales)
$dniDemo = '00000000';
$rucDemo = '00000000000';

if($perudevsToken){
    // DNI con key en query
    $dniUrl = $perudevsDniUrl ?: 'https://api.perudevs.com/api/v1/dni/complete';
    $dniMethod = strtoupper($perudevsDniMet ?: 'GET');
    $dniParam = $perudevsDniPar ?: 'document';
    $dniBody = null; $dniFinalUrl = $dniUrl;
    $sep = (strpos($dniFinalUrl,'?')===false?'?':'&');
    if (preg_match('/\{dni\}|\{document\}/i', $dniFinalUrl)) {
        $dniFinalUrl = preg_replace('/\{dni\}|\{document\}/i', urlencode($dniDemo), $dniFinalUrl);
        $sep = (strpos($dniFinalUrl,'?')===false?'?':'&');
        $dniFinalUrl .= $sep . rawurlencode($perudevsKeyPar) . '=' . rawurlencode($perudevsToken);
        if ($dniMethod !== 'POST') $dniMethod = 'GET';
    } else {
        $dniFinalUrl .= $sep . rawurlencode($dniParam) . '=' . rawurlencode($dniDemo) . '&' . rawurlencode($perudevsKeyPar) . '=' . rawurlencode($perudevsToken);
        if ($dniMethod === 'POST') { $dniBody = json_encode([$dniParam=>$dniDemo], JSON_UNESCAPED_UNICODE); }
    }
    testCall('DNI via perudevs', $dniFinalUrl, [], $dniMethod, $dniBody);

    // RUC con key en query
    $rucUrl = $perudevsRucUrl ?: 'https://api.perudevs.com/api/v1/ruc';
    $rucMethod = strtoupper($perudevsRucMet ?: 'GET');
    $rucParam = $perudevsRucPar ?: 'ruc';
    $rucBody = null; $rucFinalUrl = $rucUrl;
    $sep = (strpos($rucFinalUrl,'?')===false?'?':'&');
    if (preg_match('/\{ruc\}|\{document\}/i', $rucFinalUrl)) {
        $rucFinalUrl = preg_replace('/\{ruc\}|\{document\}/i', urlencode($rucDemo), $rucFinalUrl);
        $sep = (strpos($rucFinalUrl,'?')===false?'?':'&');
        $rucFinalUrl .= $sep . rawurlencode($perudevsKeyPar) . '=' . rawurlencode($perudevsToken);
        if ($rucMethod !== 'POST') $rucMethod = 'GET';
    } else {
        $rucFinalUrl .= $sep . rawurlencode($rucParam) . '=' . rawurlencode($rucDemo) . '&' . rawurlencode($perudevsKeyPar) . '=' . rawurlencode($perudevsToken);
        if ($rucMethod === 'POST') { $rucBody = json_encode([$rucParam=>$rucDemo], JSON_UNESCAPED_UNICODE); }
    }
    testCall('RUC via perudevs', $rucFinalUrl, [], $rucMethod, $rucBody);
}

echo "Fin diagnóstico. Reemplace los DNI/RUC de prueba por valores válidos para ver respuestas reales.\n";
