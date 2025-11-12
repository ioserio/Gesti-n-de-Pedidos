<?php
require_once __DIR__ . '/require_login.php';
require_once __DIR__ . '/conexion.php';

@ini_set('display_errors', '0');
@error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$debug = isset($_GET['debug']) && $_GET['debug'] !== '0';
$trace = [];

register_shutdown_function(function() use (&$trace, $debug){
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        $msg = isset($e['message']) ? $e['message'] : 'Fatal error';
        $payload = ['ok'=>false,'error'=>'FATAL','message'=>$msg];
        if ($debug) { $payload['trace'] = $trace; }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
});

function norm_code($code) {
    $raw = trim((string)$code);
    $noz = ltrim($raw, '0');
    if ($noz === '') { $noz = '0'; }
    return str_pad($noz, 3, '0', STR_PAD_LEFT);
}

function run_query($mysqli, $sql, &$trace, $label){
    $t = ['label'=>$label, 'sql'=>$sql];
    $res = @$mysqli->query($sql);
    if ($res instanceof mysqli_result) {
        $t['ok'] = true;
        $t['rows'] = $res->num_rows;
    } else {
        $t['ok'] = false;
        $t['err'] = $mysqli->error;
    }
    if ($trace !== null) { $trace[] = $t; }
    return $res;
}

$vendors = [];
$lastErr = '';

if ($debug) {
    $trace[] = [
        'php_version' => PHP_VERSION,
        'server' => ($_SERVER['SERVER_SOFTWARE'] ?? ''),
        'phase' => 'direct_query_vendedores'
    ];
}

// Consulta directa a vendedores(codigo,nombre)
$sql = "SELECT codigo AS cod, nombre AS nombre FROM vendedores ORDER BY CAST(codigo AS UNSIGNED) ASC";
$res = run_query($mysqli, $sql, $trace, 'vendedores_main');
if ($res && $res instanceof mysqli_result) {
    while ($row = $res->fetch_assoc()) {
        $cod = isset($row['cod']) ? $row['cod'] : '';
        if ($cod === '') continue;
        $vendors[norm_code($cod)] = [
            'cod' => norm_code($cod),
            'nombre' => isset($row['nombre']) ? trim((string)$row['nombre']) : ''
        ];
    }
    $res->free();
} else {
    if (!empty($mysqli->error)) { $lastErr = $mysqli->error; }
}

// Fallback 1: pedidos_x_dia si no hubo error y quedó vacío
if (empty($vendors) && $lastErr === '') {
    $sql2 = "SELECT TRIM(Cod_Vendedor) AS cod, MAX(TRIM(Nom_Vendedor)) AS nombre FROM pedidos_x_dia GROUP BY TRIM(Cod_Vendedor) ORDER BY TRIM(Cod_Vendedor) ASC";
    $res2 = run_query($mysqli, $sql2, $trace, 'fallback_pedidos');
    if ($res2 && $res2 instanceof mysqli_result) {
        while ($row = $res2->fetch_assoc()) {
            if ($row['cod'] === null || $row['cod'] === '') continue;
            $vendors[norm_code($row['cod'])] = [
                'cod' => norm_code($row['cod']),
                'nombre' => trim((string)$row['nombre'])
            ];
        }
        $res2->free();
    }
}

// Fallback 2: cuotas_vendedor si aún vacío y sin error
if (empty($vendors) && $lastErr === '') {
    $sql3 = "SELECT DISTINCT Cod_Vendedor AS cod FROM cuotas_vendedor ORDER BY Cod_Vendedor ASC";
    $res3 = run_query($mysqli, $sql3, $trace, 'fallback_cuotas_legacy');
    if ($res3 && $res3 instanceof mysqli_result) {
        while ($row = $res3->fetch_assoc()) {
            if (!$row['cod']) continue;
            $vendors[norm_code($row['cod'])] = [
                'cod' => norm_code($row['cod']),
                'nombre' => ''
            ];
        }
        $res3->free();
    }
}

// Filtrar a rango 001..997 y ordenar ascendente
$list = [];
foreach ($vendors as $k=>$v) {
    $n = intval(ltrim($v['cod'],'0'));
    if ($n >= 1 && $n <= 997) { $list[] = $v; }
}
if (empty($list)) {
    // Lista sintética si no se pudo obtener nada: permite que la UI funcione y alerta con 'warn'
    for ($i=1;$i<=997;$i++) {
        $list[] = [ 'cod' => str_pad((string)$i,3,'0',STR_PAD_LEFT), 'nombre' => '' ];
    }
    $lastErr = $lastErr ?: 'Sin filas en vendedores ni fallbacks; usando lista sintética';
}
usort($list, function($a,$b){ return strcmp($a['cod'],$b['cod']); });
$out = ['ok'=>true,'count'=>count($list),'vendors'=>$list,'warn'=>($lastErr?:null)];
if ($debug) { $out['trace'] = $trace; }
echo json_encode($out, JSON_UNESCAPED_UNICODE);
@$mysqli->close();
exit;

?>
