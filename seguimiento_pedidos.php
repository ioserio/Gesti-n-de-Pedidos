<?php
require_once __DIR__ . '/require_login.php';
require_once __DIR__ . '/conexion.php';

// Fecha objetivo (YYYY-MM-DD); por defecto hoy
$fecha = isset($_GET['fecha']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['fecha']) ? $_GET['fecha'] : date('Y-m-d');
$supervisor = isset($_GET['supervisor']) ? strtoupper(trim($_GET['supervisor'])) : '';
$sortPrev = isset($_GET['sort_prev']) ? strtolower(trim((string)$_GET['sort_prev'])) : '';
$sortLast = isset($_GET['sort_last']) ? strtolower(trim((string)$_GET['sort_last'])) : '';
$sortAvance = isset($_GET['sort_avance']) ? strtolower(trim((string)$_GET['sort_avance'])) : '';
if ($sortPrev !== 'asc' && $sortPrev !== 'desc') $sortPrev = '';
if ($sortLast !== 'asc' && $sortLast !== 'desc') $sortLast = '';
if ($sortAvance !== 'asc' && $sortAvance !== 'desc') $sortAvance = '';
$sortDirections = ['avance' => $sortAvance, 'prev' => $sortPrev, 'last' => $sortLast];
$sortOrder = [];
foreach (explode(',', (string)($_GET['sort_order'] ?? '')) as $key) {
    if (isset($sortDirections[$key]) && $sortDirections[$key] !== '' && !in_array($key, $sortOrder, true)) {
        $sortOrder[] = $key;
    }
}
foreach (['prev', 'last', 'avance'] as $key) {
    if ($sortDirections[$key] !== '' && !in_array($key, $sortOrder, true)) $sortOrder[] = $key;
}

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

function cuotasPorFecha(mysqli $mysqli, string $fecha): array {
    $dow = intval(date('N', strtotime($fecha)));
    $cuotas = [];

    $qh = $mysqli->prepare("SELECT Cod_Vendedor, Cuota, vigente_desde FROM cuotas_vendedor_hist WHERE Dia_Semana=? AND vigente_desde<=? ORDER BY Cod_Vendedor ASC, vigente_desde DESC");
    if ($qh) {
        $qh->bind_param('is', $dow, $fecha);
        $qh->execute();
        $rh = $qh->get_result();
        $seen = [];
        while ($rh && ($rowq = $rh->fetch_assoc())) {
            $vd = (string)$rowq['Cod_Vendedor'];
            if (!isset($seen[$vd])) {
                $cuotas[$vd] = (float)$rowq['Cuota'];
                $seen[$vd] = true;
            }
        }
        $qh->close();
    }

    if (!$cuotas) {
        $q = $mysqli->prepare("SELECT Cod_Vendedor, Cuota FROM cuotas_vendedor WHERE Dia_Semana=?");
        if ($q) {
            $q->bind_param('i', $dow);
            $q->execute();
            $r = $q->get_result();
            while ($r && ($rowq = $r->fetch_assoc())) {
                $cuotas[(string)$rowq['Cod_Vendedor']] = (float)$rowq['Cuota'];
            }
            $q->close();
        }
    }

    return $cuotas;
}

function cuotaDeVendedor(string $cod, array $cuotas): float {
    $raw = trim($cod);
    $noz = ltrim($raw, '0');
    if ($noz === '') $noz = '0';
    $pad = str_pad($noz, 3, '0', STR_PAD_LEFT);

    if (isset($cuotas[$raw])) return (float)$cuotas[$raw];
    if (isset($cuotas[$noz])) return (float)$cuotas[$noz];
    if (isset($cuotas[$pad])) return (float)$cuotas[$pad];
    return 0.0;
}

function claseAvanceSeguimiento(float $avance): string {
    if ($avance < 40) return 'seg-progress-fill is-low';
    if ($avance < 70) return 'seg-progress-fill is-mid';
    if ($avance < 100) return 'seg-progress-fill is-good';
    return 'seg-progress-fill is-top';
}

function renderAvanceSeguimiento(float $avance): string {
    $avanceRedondeado = round($avance, 1);
    $label = rtrim(rtrim(number_format($avanceRedondeado, 1, '.', ''), '0'), '.');
    if ($label === '') $label = '0';
    $label .= '%';
    $width = max(0, min(100, $avanceRedondeado));
    $class = claseAvanceSeguimiento($avanceRedondeado);

    return '<div class="seg-progress" aria-label="Avance de cuota ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">' 
        . '<div class="' . $class . '" style="width:' . number_format($width, 1, '.', '') . '%"></div>'
        . '<span class="seg-progress-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>'
        . '</div>';
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

$cuotas = cuotasPorFecha($mysqli, $fecha);

// Obtener hora de ingreso configurada para este día
$dow = intval(date('N', strtotime($fecha)));
$horaIngresoLimite = '07:30:00'; // Fallback
$resH = $mysqli->query("SELECT hora_ingreso FROM config_horarios_ingreso WHERE dia_semana = $dow");
if ($resH && $rh = $resH->fetch_assoc()) {
    $horaIngresoLimite = $rh['hora_ingreso'];
}

// Traer todos los pedidos del día con Hora y vendedor
$pedidos = [];
$ventas = [];
$stmtP = $mysqli->prepare("SELECT Cod_Vendedor, Hora, Total_IGV FROM pedidos_x_dia WHERE Fecha=? AND Hora IS NOT NULL ORDER BY Hora ASC");
if ($stmtP){
    $stmtP->bind_param('s',$fecha);
    $stmtP->execute();
    $rp = $stmtP->get_result();
    while ($rp && ($row=$rp->fetch_assoc())) {
        $vdRaw = trim((string)$row['Cod_Vendedor']);
        $vdNum = ctype_digit($vdRaw) ? str_pad(ltrim($vdRaw,'0'),3,'0',STR_PAD_LEFT) : $vdRaw;
        if (!isset($pedidos[$vdNum])) $pedidos[$vdNum] = [];
        $pedidos[$vdNum][] = $row['Hora']; // formato HH:MM:SS
        if (!isset($ventas[$vdNum])) $ventas[$vdNum] = 0.0;
        $ventas[$vdNum] += (float)str_replace(',', '', (string)$row['Total_IGV']);
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
    $f1 = null; $f2 = null; $last1 = null; $last2 = null;
    $cuota = cuotaDeVendedor($cod, $cuotas);
    $venta = isset($ventas[$cod]) ? (float)$ventas[$cod] : 0.0;
    $avance = $cuota > 0 ? (($venta / $cuota) * 100) : 0.0;
    if (count($times)) {
        // Para 1er y 2do pedido (Ascendente)
        sort($times);
        $f1Full = $times[0];
        $f1 = substr($f1Full, 0, 5);
        $diffStr = '';
        $diffStyle = '';
        if (isset($times[1])) {
            $f2Full = $times[1];
            $f2 = substr($f2Full, 0, 5);
            
            $t1 = strtotime($f1Full);
            $t2 = strtotime($f2Full);
            $diffSecs = max(0, $t2 - $t1);
            $h = floor($diffSecs / 3600);
            $m = floor(($diffSecs % 3600) / 60);
            $diffStr = sprintf('%02d:%02d', $h, $m);
            
            if ($f1Full > $horaIngresoLimite) $diffStyle = 'color: #ef4444; font-weight: 700;'; // Rojo > Configuración
            if ($diffSecs > 1800) $diffStyle = 'color: #3b82f6; font-weight: 700;';     // Azul > 30 min (prioridad)
        }

        // Para penúltimo y último (Descendente)
        rsort($times); // Orden descendente lexicográfico funciona para HH:MM:SS
        $last1 = substr($times[0],0,5);
        if (isset($times[1])) $last2 = substr($times[1],0,5);
    }
    $filas[] = [
        'cod' => $cod,
        'avance' => $avance,
        'r1' => $counts[1],
        'r2' => $counts[2],
        'r3' => $counts[3],
        'r4' => $counts[4],
        'f1' => $f1,
        'f2' => $f2,
        'diff' => $diffStr,
        'diffStyle' => $diffStyle,
        'l1' => $last1,
        'l2' => $last2
    ];
}

if ($sortOrder) {
    usort($filas, function($a, $b) use ($sortOrder, $sortDirections) {
        foreach ($sortOrder as $key) {
            $direction = $sortDirections[$key];
            if ($key === 'avance') {
                $cmp = (float)$a['avance'] <=> (float)$b['avance'];
            } else {
                $field = $key === 'prev' ? 'l2' : 'l1';
                $left = $a[$field];
                $right = $b[$field];
                if (empty($left) || empty($right)) {
                    if (empty($left) && empty($right)) continue;
                    // Las horas faltantes siempre quedan al final.
                    return empty($left) ? 1 : -1;
                }
                $cmp = strcmp((string)$left, (string)$right);
            }
            if ($cmp !== 0) return $direction === 'asc' ? $cmp : -$cmp;
        }
        return intval($a['cod']) <=> intval($b['cod']);
    });
}

$nextSortPrev = ($sortPrev === 'asc') ? 'desc' : (($sortPrev === 'desc') ? '' : 'asc');
$sortPrevArrow = ($sortPrev === 'asc') ? '▲' : (($sortPrev === 'desc') ? '▼' : '⇅');
$sortPrevTitle = ($sortPrev === 'asc') ? 'Ordenado de menor a mayor' : (($sortPrev === 'desc') ? 'Ordenado de mayor a menor' : 'Ordenar por penúltimo pedido');
$nextSortLast = ($sortLast === 'asc') ? 'desc' : (($sortLast === 'desc') ? '' : 'asc');
$sortArrow = ($sortLast === 'asc') ? '▲' : (($sortLast === 'desc') ? '▼' : '⇅');
$sortTitle = ($sortLast === 'asc') ? 'Ordenado de menor a mayor' : (($sortLast === 'desc') ? 'Ordenado de mayor a menor' : 'Ordenar por último pedido');
$nextSortAvance = ($sortAvance === 'asc') ? 'desc' : (($sortAvance === 'desc') ? '' : 'asc');
$sortAvanceArrow = ($sortAvance === 'asc') ? '▲' : (($sortAvance === 'desc') ? '▼' : '⇅');
$sortAvanceTitle = ($sortAvance === 'asc') ? 'Avance de menor a mayor' : (($sortAvance === 'desc') ? 'Avance de mayor a menor' : 'Ordenar por avance de cuota');
$sortAvanceButton = '<button type="button" class="seg-sort-btn' . ($sortAvance !== '' ? ' is-active' : '') . '" data-sort-avance="' . htmlspecialchars($nextSortAvance, ENT_QUOTES, 'UTF-8') . '" title="' . htmlspecialchars($sortAvanceTitle, ENT_QUOTES, 'UTF-8') . '" aria-label="' . htmlspecialchars($sortAvanceTitle, ENT_QUOTES, 'UTF-8') . '">Avance cuota <span class="seg-sort-icon">' . htmlspecialchars($sortAvanceArrow, ENT_QUOTES, 'UTF-8') . '</span></button>';

echo '<details class="seg-vendor-filter"><summary>Vendedores: todos</summary>';
echo '<div class="seg-vendor-filter-panel">';
echo '<div class="seg-vendor-filter-actions"><button type="button" data-seg-vendors-all>Todos</button><button type="button" data-seg-vendors-none>Ninguno</button></div>';
echo '<div class="seg-vendor-filter-list">';
foreach ($vendedores as $cod => $info) {
    echo '<label><input type="checkbox" data-seg-vendor-choice value="' . htmlspecialchars((string)$cod, ENT_QUOTES, 'UTF-8') . '"> VD ' . htmlspecialchars((string)$cod, ENT_QUOTES, 'UTF-8') . '</label>';
}
echo '</div></div></details>';
echo '<p class="seg-vendor-empty seg-vendor-hidden" data-seg-vendor-empty>No hay vendedores visibles con estos filtros.</p>';

// Render de la tabla de seguimiento (desktop)
echo '<table class="seg-desktop">';
echo '<tr><th colspan="11" data-seg-table-title style="text-align:left; background:#e6f2ff; font-size:17px;">Seguimiento del ' . htmlspecialchars($fecha) . ($supervisor ? ' — Supervisor: ' . htmlspecialchars($supervisor) : '') . ' <button onclick="window.print()" style="float:right; background:#007bff; color:#fff; border:none; padding:6px 16px; border-radius:4px; cursor:pointer; font-size:15px;">Imprimir PDF</button></th></tr>';

// Fila 1 de encabezados: Agrupación INGRESO
echo '<tr>';
echo '<th rowspan="2" data-seg-rowspan style="text-align:center;">Vendedor</th>';
echo '<th rowspan="2" data-seg-rowspan style="text-align:center; min-width:170px;">' . $sortAvanceButton . '</th>';
echo '<th rowspan="2" data-seg-rowspan style="text-align:center;">RANGO 1<br><small>7 - 9</small></th>';
echo '<th rowspan="2" data-seg-rowspan style="text-align:center;">RANGO 2<br><small>10 - 12</small></th>';
echo '<th rowspan="2" data-seg-rowspan style="text-align:center;">RANGO 3<br><small>13 - 15</small></th>';
echo '<th rowspan="2" data-seg-rowspan style="text-align:center;">RANGO 4<br><small>16 - 18</small></th>';
echo '<th colspan="3" class="seg-ingreso" style="text-align:center; background:#f8fafc; color:#1e293b; font-weight:800; border-bottom: 2px solid #cbd5e1;">INGRESO</th>';
echo '<th rowspan="2" data-seg-rowspan style="text-align:center;"><button type="button" class="seg-sort-btn' . ($sortPrev !== '' ? ' is-active' : '') . '" data-sort-prev="' . htmlspecialchars($nextSortPrev, ENT_QUOTES, 'UTF-8') . '" title="' . htmlspecialchars($sortPrevTitle, ENT_QUOTES, 'UTF-8') . '">Penultimo pedido <span class="seg-sort-icon">' . htmlspecialchars($sortPrevArrow, ENT_QUOTES, 'UTF-8') . '</span></button></th>';
echo '<th rowspan="2" data-seg-rowspan style="text-align:center;"><button type="button" class="seg-sort-btn' . ($sortLast !== '' ? ' is-active' : '') . '" data-sort-last="' . htmlspecialchars($nextSortLast, ENT_QUOTES, 'UTF-8') . '" title="' . htmlspecialchars($sortTitle, ENT_QUOTES, 'UTF-8') . '">Ultimo pedido <span class="seg-sort-icon">' . htmlspecialchars($sortArrow, ENT_QUOTES, 'UTF-8') . '</span></button></th>';
echo '</tr>';

// Fila 2: Sub-cabeceras de INGRESO
echo '<tr class="seg-ingreso">';
echo '<th style="text-align:center;">1er pedido</th>';
echo '<th style="text-align:center;">2do pedido</th>';
echo '<th style="text-align:center;">Diferencia</th>';
echo '</tr>';
foreach ($filas as $f) {
    echo '<tr data-seg-vendor-row="' . htmlspecialchars($f['cod'], ENT_QUOTES, 'UTF-8') . '">';
    // Mostrar penúltimo (l2) y último (l1) según petición
    echo '<td style="text-align:center;">' . htmlspecialchars($f['cod']) . '</td>';
    echo '<td style="text-align:center;">' . renderAvanceSeguimiento((float)$f['avance']) . '</td>';
    echo '<td style="text-align:center;">' . intval($f['r1']) . '</td>';
    echo '<td style="text-align:center;">' . intval($f['r2']) . '</td>';
    echo '<td style="text-align:center;">' . intval($f['r3']) . '</td>';
    echo '<td style="text-align:center;">' . intval($f['r4']) . '</td>';
    echo '<td class="seg-ingreso" style="text-align:center;">' . ($f['f1'] ? htmlspecialchars($f['f1']) : '—') . '</td>';
    echo '<td class="seg-ingreso" style="text-align:center;">' . ($f['f2'] ? htmlspecialchars($f['f2']) : '—') . '</td>';
    echo '<td class="seg-ingreso" style="text-align:center; ' . $f['diffStyle'] . '">' . ($f['diff'] ? htmlspecialchars($f['diff']) : '—') . '</td>';
    echo '<td style="text-align:center;">' . ($f['l2'] ? htmlspecialchars($f['l2']) : '—') . '</td>';
    echo '<td style="text-align:center;">' . ($f['l1'] ? htmlspecialchars($f['l1']) : '—') . '</td>';
    echo '</tr>';
}
echo '</table>';

// Render móvil en tarjetas
echo '<div class="seg-mobile">';
echo '<div class="seg-head">'
    . '<h3>Seguimiento del ' . htmlspecialchars($fecha) . ($supervisor ? ' — ' . htmlspecialchars($supervisor) : '') . '</h3>'
    . $sortAvanceButton
    . '<button onclick="window.print()">Imprimir PDF</button>'
    . '</div>';
foreach ($filas as $f) {
    echo '<div class="seg-card" data-seg-vendor-row="' . htmlspecialchars($f['cod'], ENT_QUOTES, 'UTF-8') . '">';
    echo   '<div class="seg-vendor">Vendedor ' . htmlspecialchars($f['cod']) . '</div>';
    echo   '<div class="seg-progress-wrap">' . renderAvanceSeguimiento((float)$f['avance']) . '</div>';
    echo   '<div class="seg-badges">'
            . '<span class="seg-badge r1">R1 (' . htmlspecialchars($rLabels[1]) . '): ' . intval($f['r1']) . '</span>'
            . '<span class="seg-badge r2">R2 (' . htmlspecialchars($rLabels[2]) . '): ' . intval($f['r2']) . '</span>'
            . '<span class="seg-badge r3">R3 (' . htmlspecialchars($rLabels[3]) . '): ' . intval($f['r3']) . '</span>'
            . '<span class="seg-badge r4">R4 (' . htmlspecialchars($rLabels[4]) . '): ' . intval($f['r4']) . '</span>'
        . '</div>';
    echo   '<div class="seg-last seg-ingreso" style="margin-bottom:6px;">'
            . '<span>1er Ped: <span class="seg-time">' . ($f['f1'] ? htmlspecialchars($f['f1']) : '—') . '</span></span>'
            . '<span>2do Ped: <span class="seg-time">' . ($f['f2'] ? htmlspecialchars($f['f2']) : '—') . '</span></span>'
            . '<span>Gap: <span style="' . $f['diffStyle'] . '">' . ($f['diff'] ? htmlspecialchars($f['diff']) : '—') . '</span></span>'
        . '</div>';
    echo   '<div class="seg-last">'
            . '<span>Penúltimo: <span class="seg-time">' . ($f['l2'] ? htmlspecialchars($f['l2']) : '—') . '</span></span>'
            . '<span>Último: <span class="seg-time">' . ($f['l1'] ? htmlspecialchars($f['l1']) : '—') . '</span></span>'
        . '</div>';
    echo '</div>';
}
echo '</div>';

$mysqli->close();
