<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/require_login.php';

require_once __DIR__ . '/conexion.php';

function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$fecha = isset($_GET['fecha']) ? trim($_GET['fecha']) : '';
$fechaDesde = isset($_GET['fecha_desde']) ? trim($_GET['fecha_desde']) : '';
$fechaHasta = isset($_GET['fecha_hasta']) ? trim($_GET['fecha_hasta']) : '';
$vd = isset($_GET['cod_vendedor']) ? trim($_GET['cod_vendedor']) : '';
$cliente = isset($_GET['cliente']) ? trim($_GET['cliente']) : '';
$supervisor = isset($_GET['supervisor']) ? trim($_GET['supervisor']) : '';
$onlyPending = isset($_GET['pendientes']) && ($_GET['pendientes'] === '1' || strtolower($_GET['pendientes']) === 'true');
// Mapa VD => SUPERVISOR (para filtros internos)
$vd_supervisor = [
    '011'=>'CARLOS','016'=>'CARLOS','023'=>'JOSE','026'=>'JESUS','028'=>'CARLOS','029'=>'CARLOS','030'=>'CARLOS',
    '102'=>'JESUS','104'=>'JOSE','106'=>'JESUS','108'=>'JOSE','110'=>'JESUS','113'=>'JESUS','114'=>'JESUS','118'=>'JESUS',
    '997'=>'FRANCISCO','777'=>'OFICINA','999'=>'OFICINA','004'=>'JOSE','005'=>'CARLOS','012'=>'JOSE','014'=>'JESUS',
    '018'=>'JESUS','024'=>'FRANCISCO','025'=>'FRANCISCO','027'=>'CARLOS','101'=>'JOSE','103'=>'JOSE','105'=>'CARLOS',
    '107'=>'JOSE','109'=>'JESUS','606'=>'FRANCISCO','607'=>'FRANCISCO','001'=>'JOSE','002'=>'JOSE','003'=>'JOSE',
    '007'=>'JOSE','008'=>'JESUS','009'=>'JESUS','010'=>'JOSE','013'=>'JESUS','021'=>'CARLOS','022'=>'CARLOS',
    '115'=>'CARLOS','116'=>'CARLOS','119'=>'CARLOS','604'=>'FRANCISCO','605'=>'FRANCISCO'
];
// Precomputar conjunto de VDs del supervisor seleccionado (si aplica)
$vendSupSet = null;
if ($supervisor !== '') {
    $vendSupSet = [];
    foreach ($vd_supervisor as $code => $sup) {
        if ($sup === $supervisor) { $vendSupSet[$code] = true; }
    }
}
// Traer devoluciones con filtros opcionales (fecha exacta o rango) y vendedor
$where = [];
$types = '';
$params = [];
if ($fecha !== '') {
    $where[] = 'd.fecha = ?'; $types .= 's'; $params[] = $fecha;
} else {
    if ($fechaDesde !== '') { $where[] = 'd.fecha >= ?'; $types .= 's'; $params[] = $fechaDesde; }
    if ($fechaHasta !== '') { $where[] = 'd.fecha <= ?'; $types .= 's'; $params[] = $fechaHasta; }
}

if ($vd !== '')    { $where[] = 'TRIM(d.codigovendedor) = ?'; $types .= 's'; $params[] = $vd; }
if ($cliente !== '') {
    // Buscar por código exacto o nombre parcial (case-insensitive)
    $where[] = '(TRIM(d.codigocliente) = ? OR LOWER(TRIM(d.nombrecliente)) LIKE ?)';
    $types .= 'ss';
    $params[] = $cliente;
    $params[] = '%' . mb_strtolower($cliente, 'UTF-8') . '%';
}
if (empty($where)) { $where[] = '1=1'; }

$sql = 'SELECT d.id, d.fecha, COALESCE(NULLIF(TRIM(d.vehiculo), \'\'), \'SIN VEHICULO\') as vehiculo,
           TRIM(d.codigovendedor) as codigovendedor, d.nombrevendedor,
           d.codigocliente, d.nombrecliente,
           d.codigoproducto, d.nombreproducto,
           ROUND(d.cantidad) as cantidad
    FROM devoluciones_por_cliente d
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY d.fecha ASC, vehiculo, d.codigocliente, d.codigoproducto';
$stmt = $mysqli->prepare($sql);
if ($types !== '') { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$res = $stmt->get_result();
$rows = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (!$rows){ echo '<p>No hay recojos para los criterios indicados.</p>'; exit; }

// Traer conteos de estados por devolucion_id en bloque
$ids = array_column($rows, 'id');
$estadosMap = [];
if (!empty($ids)) {
    $place = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmtE = $mysqli->prepare("SELECT devolucion_id, estado, COUNT(*) c FROM devoluciones_estado WHERE devolucion_id IN ($place) GROUP BY devolucion_id, estado");
    $stmtE->bind_param($types, ...$ids);
    $stmtE->execute();
    $resE = $stmtE->get_result();
    while ($r = $resE->fetch_assoc()) {
        $d = (int)$r['devolucion_id'];
        $st = (string)$r['estado'];
        $c  = (int)$r['c'];
        $estadosMap[$d][$st] = $c;
    }
    $stmtE->close();
}

// Detectar modo móvil (ancho será manejado por CSS, pero permitimos ?mobile=1 para forzar)
$forceMobile = isset($_GET['mobile']) && ($_GET['mobile'] === '1');

// Preparamos buffers separados: tabla (desktop) y tarjetas (mobile)
$cards = [];
// Para tarjetas móviles agrupadas por cliente (y fecha/vendedor/vehículo)
$groups = [];
ob_start();
echo '<table class="recojos-desktop">';
echo '<thead><tr>'
    .'<th>Fecha</th><th>Camión</th><th>Cod_Vend</th><th>Cod_Cliente</th><th>Nombre Cliente</th><th>Cod_Prod</th><th>Producto</th><th>Cant</th><th>Estado</th>'
    .'</tr></thead><tbody>';

$mostro = false;
foreach ($rows as $r) {
    // Filtrar por supervisor si se seleccionó
    if (!empty($supervisor) && is_array($vendSupSet)) {
        $vdRaw = trim((string)$r['codigovendedor']);
        $vdNoZeros = ltrim($vdRaw, '0');
        if ($vdNoZeros === '') { $vdNoZeros = '0'; }
        $vdPadded3 = str_pad($vdNoZeros, 3, '0', STR_PAD_LEFT);
        if (!isset($vendSupSet[$vdRaw]) && !isset($vendSupSet[$vdNoZeros]) && !isset($vendSupSet[$vdPadded3])) {
            continue;
        }
    }
    $id = (int)$r['id'];
    $cant = (int)$r['cantidad'];
    $veh = trim((string)$r['vehiculo']);
    if ($veh === '') $veh = 'SIN VEHICULO';
    // Incluir todos los estados usados en Gestión (agregamos 'No digitado')
    $counts = ['OK'=>0,'No llego al almacen'=>0,'Sin compra'=>0,'No autorizado'=>0,'No digitado'=>0];
    if (isset($estadosMap[$id])) {
        foreach ($estadosMap[$id] as $st => $c) { if (isset($counts[$st])) $counts[$st] = (int)$c; }
    }
    // Considerar 'No digitado' como estado asignado (no debe contarse como pendiente)
    $asignados = $counts['OK'] + $counts['No llego al almacen'] + $counts['Sin compra'] + $counts['No autorizado'] + $counts['No digitado'];
    $restan = max(0, $cant - $asignados);
    $restanOk = max(0, $cant - $counts['OK']); // unidades que aún NO están en OK
    // Si filtramos pendientes, sólo mostrar items donde todavía faltan OK
    if ($onlyPending && $restanOk <= 0) { continue; }

    // Imprimir sub-líneas por clasificación y una de pendientes si aplica (como en gestión)
    // Si está activo "pendientes", ocultamos la línea OK y mostramos sólo no-OK y Pendientes
    $lineas = [];
    if (!$onlyPending && $counts['OK'] > 0) {
        $lineas[] = ['estado' => 'OK', 'cantidad' => $counts['OK']];
    }
    if ($counts['Sin compra'] > 0) $lineas[] = ['estado' => 'Sin compra', 'cantidad' => $counts['Sin compra']];
    if ($counts['No llego al almacen'] > 0) $lineas[] = ['estado' => 'No llego al almacen', 'cantidad' => $counts['No llego al almacen']];
    if ($counts['No autorizado'] > 0) $lineas[] = ['estado' => 'No autorizado', 'cantidad' => $counts['No autorizado']];
    if ($counts['No digitado'] > 0) $lineas[] = ['estado' => 'No digitado', 'cantidad' => $counts['No digitado']];
    if ($restan > 0) $lineas[] = ['estado' => 'Pendientes', 'cantidad' => $restan];

    if (empty($lineas)) {
        // Nada que mostrar (raro, pero por seguridad)
        continue;
    }
    $mostro = true;
    // Acumular para tarjetas móviles agrupadas por cliente
    $gkey = implode('|', [
        (string)$r['fecha'], (string)$veh, (string)$r['codigovendedor'], (string)$r['codigocliente']
    ]);
    if (!isset($groups[$gkey])) {
        $groups[$gkey] = [
            'fecha' => (string)$r['fecha'],
            'vehiculo' => $veh,
            'codigovendedor' => (string)$r['codigovendedor'],
            'codigocliente' => (string)$r['codigocliente'],
            'nombrecliente' => (string)$r['nombrecliente'],
            'items' => [],
            'total_cant' => 0,
            'total_ok' => 0
        ];
    }
    $groups[$gkey]['total_cant'] += $cant;
    $groups[$gkey]['total_ok'] += $counts['OK'];
    $groups[$gkey]['items'][] = [
        'prod_cod' => (string)$r['codigoproducto'],
        'prod_nom' => (string)$r['nombreproducto'],
        'cant' => $cant,
        'ok' => $counts['OK'],
        'sin_compra' => $counts['Sin compra'],
        'no_llego' => $counts['No llego al almacen'],
        'no_aut' => $counts['No autorizado'],
        'no_digitado' => $counts['No digitado'],
        'pend' => $restan
    ];

    foreach ($lineas as $ln) {
        // Fila tabla (desktop)
        echo '<tr>'
            .'<td>' . esc($r['fecha']) . '</td>'
            .'<td>' . esc($veh) . '</td>'
            .'<td>' . esc($r['codigovendedor']) . '</td>'
            .'<td>' . esc($r['codigocliente']) . '</td>'
            .'<td>' . esc($r['nombrecliente']) . '</td>'
            .'<td>' . esc($r['codigoproducto']) . '</td>'
            .'<td>' . esc($r['nombreproducto']) . '</td>'
            .'<td style="text-align:right;">' . esc($ln['cantidad']) . '</td>'
            .'<td>' . esc($ln['estado']) . '</td>'
            .'</tr>';
        // Antes: tarjetas por estado; ahora generaremos tarjetas agrupadas más abajo
    }
}
if (!$mostro) {
    echo '<tr><td colspan="9" style="text-align:center; color:#666;">' . ($onlyPending ? 'Sin pendientes' : 'Sin resultados') . '</td></tr>';
}
echo '</tbody></table>';
$html = ob_get_clean();
// Construir bloque móvil agrupado por cliente
$cardsHtml = '';
if (!empty($groups)) {
    $out = [];
    foreach ($groups as $g) {
        $estadoGlobal = ($g['total_cant'] > 0 && $g['total_ok'] >= $g['total_cant']) ? 'OK' : 'Pendiente';
        $estadoClass = ($estadoGlobal === 'OK') ? 'est-ok' : 'est-pendientes';
        $detailRows = [];
        foreach ($g['items'] as $it) {
            $badges = [];
            if ($it['ok'] > 0) $badges[] = '<span class="rk-est est-ok">OK</span>';
            if ($it['sin_compra'] > 0) $badges[] = '<span class="rk-est est-sin-compra">Sin compra</span>';
            if ($it['no_llego'] > 0) $badges[] = '<span class="rk-est est-no-llego-al-almacen">No llegó</span>';
            if ($it['no_aut'] > 0) $badges[] = '<span class="rk-est est-no-autorizado">No aut.</span>';
            if ($it['no_digitado'] > 0) $badges[] = '<span class="rk-est est-no-digitado">No digitado</span>';
            if ($it['pend'] > 0) $badges[] = '<span class="rk-est est-pendientes">Pend.</span>';
            $detailRows[] = '<div class="rk-drow">'
                . '<div class="rk-line"><span class="rk-lbl">Prod:</span><span class="rk-val">' . esc($it['prod_cod']) . '</span></div>'
                . '<div class="rk-line rk-wide"><span class="rk-lbl">Producto:</span><span class="rk-val rk-trunc" title="' . esc($it['prod_nom']) . '">' . esc($it['prod_nom']) . '</span></div>'
                . '<div class="rk-line"><span class="rk-lbl">Cant:</span><span class="rk-val">' . esc($it['cant']) . '</span></div>'
                . '<div class="rk-line rk-wide">' . implode(' ', $badges) . '</div>'
                . '</div>';
        }
        $out[] = '<div class="rk-card" tabindex="0">'
            . '<div class="rk-head"><span class="rk-fecha">' . esc($g['fecha']) . '</span><span class="rk-vd">VD ' . esc($g['codigovendedor']) . ' · CM: ' . esc($g['vehiculo']) . '</span></div>'
            . '<div class="rk-body">'
                . '<div class="rk-line"><span class="rk-lbl">Cli:</span><span class="rk-val">' . esc($g['codigocliente']) . '</span></div>'
                . '<div class="rk-line"><span class="rk-lbl">Est:</span><span class="rk-est ' . $estadoClass . '">' . esc($estadoGlobal) . '</span></div>'
                . '<div class="rk-line rk-wide"><span class="rk-lbl">Cliente:</span><span class="rk-val rk-trunc" title="' . esc($g['nombrecliente']) . '">' . esc($g['nombrecliente']) . '</span></div>'
            . '</div>'
            . '<div class="rk-detail" style="display:none;">' . implode('', $detailRows) . '</div>'
            . '</div>';
    }
    $cardsHtml = '<div class="recojos-mobile-grid">' . implode('', $out) . '</div>';
}
// Salida combinada: ambos bloques, CSS ocultará según ancho
echo ($html ?: '<p>Sin resultados.</p>') . $cardsHtml;
