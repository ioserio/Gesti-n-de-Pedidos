<?php
require_once __DIR__ . '/require_login.php';
require_once __DIR__ . '/conexion.php';

// Fecha objetivo (YYYY-MM-DD); por defecto hoy
$fecha = isset($_GET['fecha']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['fecha']) ? $_GET['fecha'] : date('Y-m-d');
$supervisor = isset($_GET['supervisor']) ? strtoupper(trim($_GET['supervisor'])) : '';

// Definición de rangos (exactamente como imagen):
$rangos = [
    ['id'=>1,'h_ini'=>'07:00:00','h_fin'=>'09:59:59'],
    ['id'=>2,'h_ini'=>'10:00:00','h_fin'=>'12:59:59'],
    ['id'=>3,'h_ini'=>'13:00:00','h_fin'=>'15:59:59'],
    ['id'=>4,'h_ini'=>'16:00:00','h_fin'=>'18:59:59'],
];
// Etiquetas de horas por rango (p.ej. 7 - 9)
$rLabels = [];
foreach ($rangos as $rg) {
    $iniH = (int)substr($rg['h_ini'], 0, 2);
    $finH = (int)substr($rg['h_fin'], 0, 2);
    $rLabels[$rg['id']] = $iniH . ' - ' . $finH;
}

// Mapa vendedor => supervisor (igual al usado en resumen)
$vd_supervisor = [
    '011'=>'CARLOS','016'=>'CARLOS','023'=>'JOSE','026'=>'JESUS','028'=>'CARLOS','029'=>'CARLOS','030'=>'CARLOS',
    '102'=>'JESUS','104'=>'JOSE','106'=>'JESUS','108'=>'JOSE','110'=>'JESUS','113'=>'JESUS','114'=>'JESUS','118'=>'JESUS',
    '997'=>'FRANCISCO','777'=>'OFICINA','999'=>'OFICINA','004'=>'JOSE','005'=>'CARLOS','012'=>'JOSE','014'=>'JESUS',
    '018'=>'JESUS','024'=>'FRANCISCO','025'=>'FRANCISCO','027'=>'CARLOS','101'=>'JOSE','103'=>'JOSE','105'=>'CARLOS',
    '107'=>'JOSE','109'=>'JESUS','606'=>'FRANCISCO','607'=>'FRANCISCO','001'=>'JOSE','002'=>'JOSE','003'=>'JOSE',
    '007'=>'JOSE','008'=>'JESUS','009'=>'JESUS','010'=>'JOSE','013'=>'JESUS','021'=>'CARLOS','022'=>'CARLOS',
    '115'=>'CARLOS','116'=>'CARLOS','119'=>'CARLOS','604'=>'FRANCISCO','605'=>'FRANCISCO'
];
function supervisorDe($cod,$map){
    $raw = trim((string)$cod);
    $noz = ltrim($raw,'0'); if ($noz==='') $noz='0';
    $pad = str_pad($noz,3,'0',STR_PAD_LEFT);
    if (isset($map[$raw])) return $map[$raw];
    if (isset($map[$noz])) return $map[$noz];
    if (isset($map[$pad])) return $map[$pad];
    return '';
}

// Obtener lista completa de vendedores (codigo) para mostrar todos aunque tengan 0 pedidos
$vendedores = [];
$resVend = $mysqli->query("SELECT TRIM(codigo) AS cod FROM vendedores WHERE TRIM(codigo) REGEXP '^[0-9]{1,3}$' OR LENGTH(TRIM(codigo))<=3");
if ($resVend) {
    while ($rw = $resVend->fetch_assoc()) {
        $c = strtoupper(trim($rw['cod']));
        if ($c !== '') {
            // Normalizar a 3 dígitos si es numérico
            if (ctype_digit($c)) $c = str_pad(ltrim($c,'0'),3,'0',STR_PAD_LEFT);
            $vendedores[$c] = ['cod'=>$c];
        }
    }
}
// Fallback si no hay vendedores en tabla dedicada: usar los presentes en pedidos_x_dia
if (!count($vendedores)) {
    $resVend2 = $mysqli->query("SELECT DISTINCT TRIM(Cod_Vendedor) AS cod FROM pedidos_x_dia");
    while ($resVend2 && ($rw = $resVend2->fetch_assoc())) {
        $c = strtoupper(trim($rw['cod']));
        if ($c !== '') { if (ctype_digit($c)) $c = str_pad(ltrim($c,'0'),3,'0',STR_PAD_LEFT); $vendedores[$c] = ['cod'=>$c]; }
    }
}

// Asegurar orden por código numérico (manteniendo formato string 3 dígitos)
uksort($vendedores, function($a,$b){ return intval($a) <=> intval($b); });

// Traer todos los pedidos del día con Hora y vendedor
$pedidos = [];
$stmtP = $mysqli->prepare("SELECT Cod_Vendedor, Hora FROM pedidos_x_dia WHERE Fecha=? AND Hora IS NOT NULL ORDER BY Hora ASC");
if ($stmtP){
    $stmtP->bind_param('s',$fecha);
    $stmtP->execute();
    $rp = $stmtP->get_result();
    while ($rp && ($row=$rp->fetch_assoc())) {
        $vdRaw = trim((string)$row['Cod_Vendedor']);
        $vdNum = ctype_digit($vdRaw) ? str_pad(ltrim($vdRaw,'0'),3,'0',STR_PAD_LEFT) : $vdRaw;
        if (!isset($pedidos[$vdNum])) $pedidos[$vdNum] = [];
        $pedidos[$vdNum][] = $row['Hora']; // formato HH:MM:SS
    }
    $stmtP->close();
}

// Calcular métricas por vendedor
$filas = [];
foreach ($vendedores as $cod => $info) {
    // Filtrar por supervisor si se solicitó
    if ($supervisor !== '' && supervisorDe($cod, $vd_supervisor) !== $supervisor) {
        continue;
    }
    $times = isset($pedidos[$cod]) ? $pedidos[$cod] : [];
    // Contadores por rango
    $counts = [1=>0,2=>0,3=>0,4=>0];
    foreach ($times as $hora) {
        foreach ($rangos as $rg) {
            if ($hora >= $rg['h_ini'] && $hora <= $rg['h_fin']) { $counts[$rg['id']]++; break; }
        }
    }
    // Últimas dos horas generales (desc)
    $last1 = null; $last2 = null;
    if (count($times)) {
        rsort($times); // Orden descendente lexicográfico funciona para HH:MM:SS
        $last1 = substr($times[0],0,5);
        if (isset($times[1])) $last2 = substr($times[1],0,5);
    }
    $filas[] = [
        'cod' => $cod,
        'r1' => $counts[1],
        'r2' => $counts[2],
        'r3' => $counts[3],
        'r4' => $counts[4],
        'l1' => $last1,
        'l2' => $last2
    ];
}

// Render de la tabla de seguimiento (desktop)
echo '<table class="seg-desktop">';
echo '<tr><th colspan="7" style="text-align:left; background:#e6f2ff; font-size:17px;">Seguimiento del ' . htmlspecialchars($fecha) . ($supervisor ? ' — Supervisor: ' . htmlspecialchars($supervisor) : '') . ' <button onclick="window.print()" style="float:right; background:#007bff; color:#fff; border:none; padding:6px 16px; border-radius:4px; cursor:pointer; font-size:15px;">Imprimir PDF</button></th></tr>';
echo '<tr>';
echo '<th style="text-align:center;">Vendedor</th>';
echo '<th style="text-align:center;">RANGO 1<br><small>7 - 9</small></th>';
echo '<th style="text-align:center;">RANGO 2<br><small>10 - 12</small></th>';
echo '<th style="text-align:center;">RANGO 3<br><small>13 - 15</small></th>';
echo '<th style="text-align:center;">RANGO 4<br><small>16 - 18</small></th>';
echo '<th style="text-align:center;">Penultimo pedido</th>';
echo '<th style="text-align:center;">Ultimo pedido</th>';
echo '</tr>';
foreach ($filas as $f) {
    echo '<tr>';
    // Mostrar penúltimo (l2) y último (l1) según petición
    echo '<td style="text-align:center;">' . htmlspecialchars($f['cod']) . '</td>';
    echo '<td style="text-align:center;">' . intval($f['r1']) . '</td>';
    echo '<td style="text-align:center;">' . intval($f['r2']) . '</td>';
    echo '<td style="text-align:center;">' . intval($f['r3']) . '</td>';
    echo '<td style="text-align:center;">' . intval($f['r4']) . '</td>';
    echo '<td style="text-align:center;">' . ($f['l2'] ? htmlspecialchars($f['l2']) : '—') . '</td>';
    echo '<td style="text-align:center;">' . ($f['l1'] ? htmlspecialchars($f['l1']) : '—') . '</td>';
    echo '</tr>';
}
echo '</table>';

// Render móvil en tarjetas
echo '<div class="seg-mobile">';
echo '<div class="seg-head">'
    . '<h3>Seguimiento del ' . htmlspecialchars($fecha) . ($supervisor ? ' — ' . htmlspecialchars($supervisor) : '') . '</h3>'
    . '<button onclick="window.print()">Imprimir PDF</button>'
    . '</div>';
foreach ($filas as $f) {
    echo '<div class="seg-card">';
    echo   '<div class="seg-vendor">Vendedor ' . htmlspecialchars($f['cod']) . '</div>';
    echo   '<div class="seg-badges">'
            . '<span class="seg-badge r1">R1 (' . htmlspecialchars($rLabels[1]) . '): ' . intval($f['r1']) . '</span>'
            . '<span class="seg-badge r2">R2 (' . htmlspecialchars($rLabels[2]) . '): ' . intval($f['r2']) . '</span>'
            . '<span class="seg-badge r3">R3 (' . htmlspecialchars($rLabels[3]) . '): ' . intval($f['r3']) . '</span>'
            . '<span class="seg-badge r4">R4 (' . htmlspecialchars($rLabels[4]) . '): ' . intval($f['r4']) . '</span>'
        . '</div>';
    echo   '<div class="seg-last">'
            . '<span>Penúltimo: <span class="seg-time">' . ($f['l2'] ? htmlspecialchars($f['l2']) : '—') . '</span></span>'
            . '<span>Último: <span class="seg-time">' . ($f['l1'] ? htmlspecialchars($f['l1']) : '—') . '</span></span>'
        . '</div>';
    echo '</div>';
}
echo '</div>';

$mysqli->close();
