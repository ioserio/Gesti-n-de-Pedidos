<?php
require_once __DIR__ . '/require_login.php';
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/conexion.php';

$fecha = isset($_GET['fecha']) ? $_GET['fecha'] : date('Y-m-d');
$supervisor = isset($_GET['supervisor']) ? trim((string)$_GET['supervisor']) : '';

// Tabla de cuota mensual global
$mysqli->query("CREATE TABLE IF NOT EXISTS cuotas_mensuales_global (
    id INT AUTO_INCREMENT PRIMARY KEY,
    Anio SMALLINT NOT NULL,
    Mes TINYINT NOT NULL,
    Cuota DECIMAL(12,2) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cuota_mensual_global (Anio, Mes),
    KEY idx_anio_mes (Anio, Mes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$vd_supervisor = [
    '011'=>'CARLOS','016'=>'CARLOS','023'=>'JOSE','026'=>'JESUS','028'=>'CARLOS','029'=>'CARLOS','030'=>'CARLOS',
    '102'=>'JESUS','104'=>'JOSE','106'=>'JESUS','108'=>'JOSE','110'=>'JESUS','113'=>'JESUS','114'=>'JESUS','118'=>'JESUS',
    '997'=>'FRANCISCO','777'=>'OFICINA','999'=>'OFICINA','004'=>'JOSE','005'=>'CARLOS','012'=>'JOSE','014'=>'JESUS',
    '018'=>'JESUS','024'=>'FRANCISCO','025'=>'FRANCISCO','027'=>'CARLOS','101'=>'JOSE','103'=>'JOSE','105'=>'CARLOS',
    '107'=>'JOSE','109'=>'JESUS','606'=>'FRANCISCO','607'=>'FRANCISCO','001'=>'JOSE','002'=>'JOSE','003'=>'JOSE',
    '007'=>'JOSE','008'=>'JESUS','009'=>'JESUS','010'=>'JOSE','013'=>'JESUS','021'=>'CARLOS','022'=>'CARLOS',
    '115'=>'CARLOS','116'=>'CARLOS','119'=>'CARLOS','604'=>'FRANCISCO','605'=>'FRANCISCO'
];

function resolveSupervisorForVendedor(string $cod, array $map): string {
    $vdRaw = trim($cod);
    $vdNoZeros = ltrim($vdRaw, '0');
    if ($vdNoZeros === '') { $vdNoZeros = '0'; }
    $vdPadded3 = str_pad($vdNoZeros, 3, '0', STR_PAD_LEFT);

    if (isset($map[$vdRaw])) return $map[$vdRaw];
    if (isset($map[$vdNoZeros])) return $map[$vdNoZeros];
    if (isset($map[$vdPadded3])) return $map[$vdPadded3];
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
        while ($rowq = $rh->fetch_assoc()) {
            $vd = (string)$rowq['Cod_Vendedor'];
            if (!isset($seen[$vd])) {
                $cuotas[$vd] = (float)$rowq['Cuota'];
                $seen[$vd] = true;
            }
        }
        $qh->close();
    }

    if (count($cuotas) === 0) {
        $q = $mysqli->prepare("SELECT Cod_Vendedor, Cuota FROM cuotas_vendedor WHERE Dia_Semana=?");
        if ($q) {
            $q->bind_param('i', $dow);
            $q->execute();
            $r = $q->get_result();
            while ($rowq = $r->fetch_assoc()) {
                $cuotas[(string)$rowq['Cod_Vendedor']] = (float)$rowq['Cuota'];
            }
            $q->close();
        }
    }

    return $cuotas;
}

function totalizarFecha(mysqli $mysqli, string $fecha, string $supervisor, array $vd_supervisor): array {
    $stmt = $mysqli->prepare("SELECT Cod_Vendedor, COUNT(*) AS ctd_pedidos, SUM(CAST(REPLACE(Total_IGV, ',', '') AS DECIMAL(12,2))) AS total_igv FROM pedidos_x_dia WHERE Fecha = ? GROUP BY Cod_Vendedor");
    if (!$stmt) {
        return ['pedidos' => 0, 'venta' => 0.0, 'cuota' => 0.0, 'faltante' => 0.0, 'avance' => 0];
    }

    $stmt->bind_param('s', $fecha);
    $stmt->execute();
    $result = $stmt->get_result();
    $cuotas = cuotasPorFecha($mysqli, $fecha);

    $totalPedidos = 0;
    $totalVenta = 0.0;
    $totalCuota = 0.0;

    while ($row = $result->fetch_assoc()) {
        $cod = (string)$row['Cod_Vendedor'];
        $sup = resolveSupervisorForVendedor($cod, $vd_supervisor);
        if ($supervisor !== '' && $sup !== $supervisor) {
            continue;
        }

        $codNoZeros = ltrim(trim($cod), '0');
        if ($codNoZeros === '') { $codNoZeros = '0'; }
        $codPadded3 = str_pad($codNoZeros, 3, '0', STR_PAD_LEFT);

        $cuotaVal = 0.0;
        if (isset($cuotas[$cod])) $cuotaVal = (float)$cuotas[$cod];
        elseif (isset($cuotas[$codNoZeros])) $cuotaVal = (float)$cuotas[$codNoZeros];
        elseif (isset($cuotas[$codPadded3])) $cuotaVal = (float)$cuotas[$codPadded3];

        $totalPedidos += (int)$row['ctd_pedidos'];
        $totalVenta += (float)$row['total_igv'];
        $totalCuota += $cuotaVal;
    }

    $stmt->close();

    $pctRaw = $totalCuota > 0 ? (($totalVenta / $totalCuota) * 100) : 0;
    $avance = ($pctRaw < 100) ? floor($pctRaw) : round($pctRaw);

    $faltante = max(0, $totalCuota - $totalVenta);

    return [
        'pedidos' => $totalPedidos,
        'venta' => $totalVenta,
        'cuota' => $totalCuota,
        'faltante' => $faltante,
        'avance' => (int)$avance
    ];
}

/**
 * Cuenta días hábiles de facturación (lunes a sábado) entre dos fechas inclusive.
 */
function countBusinessDays(string $startDate, string $endDate): int {
    $start = strtotime($startDate);
    $end = strtotime($endDate);
    if ($end < $start) return 0;
    $count = 0;
    for ($t = $start; $t <= $end; $t = strtotime('+1 day', $t)) {
        $dow = (int)date('N', $t); // 1=lunes ... 7=domingo
        if ($dow >= 1 && $dow <= 6) {
            $count++;
        }
    }
    return $count;
}

/** Devuelve true si la fecha es día hábil de venta (lunes a sábado). */
function isBusinessDay(string $date): bool {
    $t = strtotime($date);
    if ($t === false) return false;
    $dow = (int)date('N', $t); // 1=lunes ... 7=domingo
    return ($dow >= 1 && $dow <= 6);
}

$fechas = [];
for ($i = 0; $i < 4; $i++) {
    $offset = $i * 7;
    $fechas[] = date('Y-m-d', strtotime($fecha . ' -' . $offset . ' days'));
}

$dias = [1=>'Lunes',2=>'Martes',3=>'Miércoles',4=>'Jueves',5=>'Viernes',6=>'Sábado',7=>'Domingo'];
$diaBase = $dias[(int)date('N', strtotime($fecha))] ?? 'Día';

// Indicador global mensual (siempre global, sin filtro por supervisor)
$anioSel = (int)date('Y', strtotime($fecha));
$mesSel = (int)date('n', strtotime($fecha));
$mesIni = date('Y-m-01', strtotime($fecha));
$mesFin = date('Y-m-t', strtotime($fecha));
$meses = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];

$cuotaMensualGlobal = 0.0;
$qm = $mysqli->prepare("SELECT Cuota FROM cuotas_mensuales_global WHERE Anio=? AND Mes=? LIMIT 1");
if ($qm) {
    $qm->bind_param('ii', $anioSel, $mesSel);
    $qm->execute();
    $rm = $qm->get_result();
    if ($rm && ($rowm = $rm->fetch_assoc())) {
        $cuotaMensualGlobal = (float)$rowm['Cuota'];
    }
    $qm->close();
}

$ventaMensualGlobal = 0.0;
$qvm = $mysqli->prepare("SELECT SUM(CAST(REPLACE(Total_IGV, ',', '') AS DECIMAL(12,2))) AS total_igv FROM pedidos_x_dia WHERE Fecha BETWEEN ? AND ?");
if ($qvm) {
    // Venta acumulada del mes hasta la fecha seleccionada, limitada al último día facturable del mes
    $diaMes = (int)date('j', strtotime($fecha));
    $diasMes = (int)date('t', strtotime($fecha));
    $diasFacturablesMes = max(1, $diasMes - 1); // último día de pedidos factura al mes siguiente
    $anioMes = date('Y-m', strtotime($fecha));
    $ultimaFacturable = $anioMes . '-' . str_pad((string)$diasFacturablesMes, 2, '0', STR_PAD_LEFT);
    // Incluir también el último día del mes anterior en el acumulado del mes actual
    $inicioVentasMes = date('Y-m-d', strtotime($mesIni . ' -1 day'));

    $fechaCorte = $fecha;
    if ($fechaCorte < $mesIni) { $fechaCorte = $mesIni; }
    if ($fechaCorte > $ultimaFacturable) { $fechaCorte = $ultimaFacturable; }

    $qvm->bind_param('ss', $inicioVentasMes, $fechaCorte);
    $qvm->execute();
    $rvm = $qvm->get_result();
    if ($rvm && ($rowm = $rvm->fetch_assoc())) {
        $ventaMensualGlobal = (float)$rowm['total_igv'];
    }
    $qvm->close();
}

$faltanteMensualGlobal = max(0, $cuotaMensualGlobal - $ventaMensualGlobal);
$diaMes = (int)date('j', strtotime($fecha));
$diasMes = (int)date('t', strtotime($fecha));
$diasFacturablesMes = max(1, $diasMes - 1);
$anioMes = date('Y-m', strtotime($fecha));
$ultimaFacturable = $anioMes . '-' . str_pad((string)$diasFacturablesMes, 2, '0', STR_PAD_LEFT);

// Fecha límite para el cálculo de días transcurridos (no más allá del último día facturable)
$fechaLimite = $fecha;
if ($fechaLimite < $mesIni) { $fechaLimite = $mesIni; }
if ($fechaLimite > $ultimaFacturable) { $fechaLimite = $ultimaFacturable; }

// Días hábiles de venta transcurridos (lunes a sábado) en el mes actual
// Nota de negocio: se suma también el último día hábil del mes anterior al mes actual.
$diasHabilesTranscurridos = countBusinessDays($mesIni, $fechaLimite);
$diasHabilesMes = countBusinessDays($mesIni, $ultimaFacturable);
// Último día del mes anterior
$ultimoDiaMesAnterior = date('Y-m-d', strtotime($mesIni . ' -1 day'));
if (isBusinessDay($ultimoDiaMesAnterior)) {
    // Siempre suma al total de días hábiles del mes
    $diasHabilesMes++;
    // Y ya debe considerarse como "transcurrido" cuando estamos dentro de este mes
    // (es la venta que se factura el primer día del mes actual)
    $diasHabilesTranscurridos++;
}

$diasHabilesFaltantes = max(0, $diasHabilesMes - $diasHabilesTranscurridos);

$proyeccionMensual = 0.0;
if ($diasHabilesTranscurridos > 0 && $diasHabilesMes > 0) {
    $proyeccionMensual = ($ventaMensualGlobal / $diasHabilesTranscurridos) * $diasHabilesMes;
}
$desvProyCuota = $proyeccionMensual - $cuotaMensualGlobal;
$pctMensualRaw = $cuotaMensualGlobal > 0 ? (($ventaMensualGlobal / $cuotaMensualGlobal) * 100) : 0;
$pctMensual = ($pctMensualRaw < 100) ? floor($pctMensualRaw) : round($pctMensualRaw);
$pctMensualCap = max(0, min(100, $pctMensual));
$mBarClass = 'bar-red';
if ($pctMensual >= 100) { $mBarClass = 'bar-green'; }
elseif ($pctMensual >= 80) { $mBarClass = 'bar-yellow'; }
elseif ($pctMensual >= 50) { $mBarClass = 'bar-orange'; }

echo '<div class="cuota-mes-side">';
echo '<h3>Cuota del Mes</h3>';
echo '<p class="historico-sub">' . htmlspecialchars(($meses[$mesSel] ?? 'Mes') . ' ' . $anioSel) . '</p>';
echo '<p class="historico-sub">Días hábiles: ' . $diasHabilesTranscurridos . ' de ' . $diasHabilesMes . ' (faltan ' . $diasHabilesFaltantes . ')</p>';
echo '<div class="historico-metrics-grid">';
echo '<div class="hm"><small>Cuota Mes</small><b>S/ ' . number_format($cuotaMensualGlobal, 2, '.', ',') . '</b></div>';
echo '<div class="hm"><small>Venta Mes</small><b>S/ ' . number_format($ventaMensualGlobal, 2, '.', ',') . '</b></div>';
echo '<div class="hm"><small>Faltante</small><b>S/ ' . number_format($faltanteMensualGlobal, 2, '.', ',') . '</b></div>';
echo '<div class="hm"><small>Avance</small><b>' . $pctMensual . '%</b></div>';
echo '<div class="hm"><small>Proyección Mes</small><b>S/ ' . number_format($proyeccionMensual, 2, '.', ',') . '</b></div>';
echo '<div class="hm"><small>Desv. Proy vs Cuota</small><b>'
    . ($desvProyCuota >= 0 ? '+S/ ' : '-S/ ')
    . number_format(abs($desvProyCuota), 2, '.', ',')
    . '</b></div>';
echo '</div>';
echo '<div class="progress historico-progress"><div class="bar ' . $mBarClass . '" style="width:' . $pctMensualCap . '%"></div></div>';
echo '</div>';

echo '<div class="historico-side">';
echo '<h3>Histórico 4 semanas</h3>';
echo '<p class="historico-sub">' . htmlspecialchars($diaBase) . 's recientes' . ($supervisor !== '' ? (' · ' . htmlspecialchars($supervisor)) : '') . '</p>';
echo '<div class="historico-list">';

foreach ($fechas as $f) {
    $tot = totalizarFecha($mysqli, $f, $supervisor, $vd_supervisor);
    $pct = (int)$tot['avance'];
    $pctCap = max(0, min(100, $pct));

    $barClass = 'bar-red';
    if ($pct >= 100) { $barClass = 'bar-green'; }
    elseif ($pct >= 80) { $barClass = 'bar-yellow'; }
    elseif ($pct >= 50) { $barClass = 'bar-orange'; }

    echo '<div class="historico-item">';
    echo '<div class="historico-head">';
    echo '<strong>' . htmlspecialchars(date('d/m/Y', strtotime($f))) . '</strong>';
    echo '<span>' . $pct . '%</span>';
    echo '</div>';
    echo '<div class="historico-metrics-grid">';
    echo '<div class="hm"><small>Venta</small><b>S/ ' . number_format((float)$tot['venta'], 2, '.', ',') . '</b></div>';
    echo '<div class="hm"><small>Cuota</small><b>S/ ' . number_format((float)$tot['cuota'], 2, '.', ',') . '</b></div>';
    echo '<div class="hm"><small>Faltante</small><b>S/ ' . number_format((float)$tot['faltante'], 2, '.', ',') . '</b></div>';
    echo '<div class="hm"><small>Pedidos</small><b>' . (int)$tot['pedidos'] . '</b></div>';
    echo '</div>';
    echo '<div class="progress historico-progress"><div class="bar ' . $barClass . '" style="width:' . $pctCap . '%"></div></div>';
    echo '</div>';
}

echo '</div>';
echo '</div>';

$mysqli->close();
