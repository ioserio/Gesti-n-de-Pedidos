<?php
require_once __DIR__ . '/require_login.php';
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/dias_habiles_helper.php';

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

function resumenHistoricoProgressClass(float $avance): string {
    if ($avance < 40) return 'seg-progress-fill is-low';
    if ($avance < 70) return 'seg-progress-fill is-mid';
    if ($avance < 100) return 'seg-progress-fill is-good';
    return 'seg-progress-fill is-top';
}

function renderResumenHistoricoProgress(float $avance, string $extraClass = ''): string {
    $avanceRedondeado = round($avance, 1);
    $label = rtrim(rtrim(number_format($avanceRedondeado, 1, '.', ''), '0'), '.');
    if ($label === '') $label = '0';
    $label .= '%';
    $width = max(0, min(100, $avanceRedondeado));
    $class = trim('seg-progress ' . $extraClass);

    return '<div class="' . $class . '" aria-label="Avance ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">' 
        . '<div class="' . resumenHistoricoProgressClass($avanceRedondeado) . '" style="width:' . number_format($width, 1, '.', '') . '%"></div>'
        . '<span class="seg-progress-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>'
        . '</div>';
}

function scaleVerticalChartValue(float $value, float $minValue, float $maxValue, float $left, float $width): float {
    $range = $maxValue - $minValue;
    if ($range <= 0) {
        return $left + ($width / 2);
    }
    return $left + ((($value - $minValue) / $range) * $width);
}

function buildVerticalChartPath(array $values, float $left, float $top, float $width, float $rowStep, float $minValue, float $maxValue): string {
    if (count($values) === 0) return '';

    $path = '';
    $started = false;
    foreach ($values as $index => $value) {
        if ($value === null) {
            $started = false;
            continue;
        }
        $x = scaleVerticalChartValue((float)$value, $minValue, $maxValue, $left, $width);
        $y = $top + ($rowStep * $index);
        $path .= ($started ? ' L ' : 'M ') . number_format($x, 2, '.', '') . ' ' . number_format($y, 2, '.', '');
        $started = true;
    }
    return trim($path);
}

function renderResumenVerticalMonthChart(mysqli $mysqli, string $fecha, string $supervisor, array $vd_supervisor): string {
    try {
        $selectedDate = new DateTimeImmutable($fecha);
    } catch (Throwable $e) {
        return '';
    }

    $monthStart = $selectedDate->modify('first day of this month');
    $monthEnd = $selectedDate->modify('last day of this month');
    $salesByDay = [];

    $stmtMonth = $mysqli->prepare("SELECT Fecha, Cod_Vendedor, SUM(CAST(REPLACE(Total_IGV, ',', '') AS DECIMAL(12,2))) AS total_igv FROM pedidos_x_dia WHERE Fecha BETWEEN ? AND ? GROUP BY Fecha, Cod_Vendedor ORDER BY Fecha ASC, Cod_Vendedor ASC");
    if ($stmtMonth) {
        $monthStartStr = $monthStart->format('Y-m-d');
        $monthEndStr = $monthEnd->format('Y-m-d');
        $stmtMonth->bind_param('ss', $monthStartStr, $monthEndStr);
        $stmtMonth->execute();
        $resultMonth = $stmtMonth->get_result();
        while ($row = $resultMonth->fetch_assoc()) {
            $vendorCode = (string)$row['Cod_Vendedor'];
            $vendorSupervisor = resolveSupervisorForVendedor($vendorCode, $vd_supervisor);
            if ($supervisor !== '' && $vendorSupervisor !== $supervisor) continue;
            $day = (string)$row['Fecha'];
            if (!isset($salesByDay[$day])) $salesByDay[$day] = 0.0;
            $salesByDay[$day] += (float)$row['total_igv'];
        }
        $stmtMonth->close();
    }

    $chartDates = [];
    $quotaValues = [];
    $salesValues = [];
    $dailyQuotaByDay = [];
    $dailySalesByDay = [];
    $minObservedValue = null;
    $maxValue = 0.0;
    $cursor = $monthStart;
    $selectedDateStr = $selectedDate->format('Y-m-d');
    $businessDaysMap = getDiasHabilesMonth($mysqli, (int)$selectedDate->format('Y'), (int)$selectedDate->format('n'));

    while ($cursor <= $monthEnd) {
        $dayStr = $cursor->format('Y-m-d');
        $dailyQuota = 0.0;
        foreach (cuotasPorFecha($mysqli, $dayStr) as $vendorCode => $quotaValue) {
            $vendorSupervisor = resolveSupervisorForVendedor((string)$vendorCode, $vd_supervisor);
            if ($supervisor !== '' && $vendorSupervisor !== $supervisor) continue;
            $dailyQuota += (float)$quotaValue;
        }

        $dailySale = ($dayStr <= $selectedDateStr) ? (float)($salesByDay[$dayStr] ?? 0.0) : null;
        $dailyQuotaByDay[$dayStr] = $dailyQuota;
        $dailySalesByDay[$dayStr] = $dailySale;

        if (!empty($businessDaysMap[$dayStr])) {
            $chartDates[] = $dayStr;
            $quotaValues[] = $dailyQuota;
            $salesValues[] = $dailySale;
            $maxValue = max($maxValue, $dailyQuota, $dailySale ?? 0.0);
            $minObservedValue = ($minObservedValue === null) ? $dailyQuota : min($minObservedValue, $dailyQuota);
            if ($dailySale !== null) {
                $minObservedValue = min($minObservedValue, (float)$dailySale);
            }
        }

        $cursor = $cursor->modify('+1 day');
    }

    if (empty($chartDates)) return '';

    $selectedSale = (float)($dailySalesByDay[$selectedDateStr] ?? 0.0);
    $selectedQuota = (float)($dailyQuotaByDay[$selectedDateStr] ?? 0.0);
    $selectedPctRaw = $selectedQuota > 0 ? (($selectedSale / $selectedQuota) * 100) : 0;
    $selectedPct = ($selectedPctRaw < 100) ? floor($selectedPctRaw) : round($selectedPctRaw);
    $selectedFaltante = max(0.0, $selectedQuota - $selectedSale);
    $selectedDateLabel = date('d/m/Y', strtotime($selectedDateStr));

    $width = 300;
    $rowStep = 19;
    $top = 24;
    $left = 52;
    $right = 16;
    $bottom = 20;
    $plotWidth = $width - $left - $right;
    $plotHeight = max(420, ($rowStep * (count($chartDates) - 1)) + 6);
    $height = $top + $plotHeight + $bottom;
    $minObservedValue = $minObservedValue ?? 0.0;
    $range = max(1.0, $maxValue - $minObservedValue);
    $padding = max($range * 0.15, $maxValue * 0.03, 1.0);
    $scaleMin = max(0.0, $minObservedValue - $padding);
    $scaleMax = max($maxValue + $padding, $scaleMin + 1.0);
    $selectedIndex = array_search($selectedDateStr, $chartDates, true);
    $selectedY = ($selectedIndex === false) ? null : ($top + ($rowStep * $selectedIndex));
    $quotaPath = buildVerticalChartPath($quotaValues, $left, $top, $plotWidth, $rowStep, $scaleMin, $scaleMax);
    $salesPath = buildVerticalChartPath($salesValues, $left, $top, $plotWidth, $rowStep, $scaleMin, $scaleMax);
    $xMid = $left + ($plotWidth / 2);
    $xMax = $left + $plotWidth;
    $monthNames = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
    $monthLabel = ($monthNames[(int)$selectedDate->format('n')] ?? $selectedDate->format('F')) . ' ' . $selectedDate->format('Y');
    $scopeLabel = $supervisor !== '' ? ('Supervisor ' . htmlspecialchars($supervisor, ENT_QUOTES, 'UTF-8')) : 'Todos los vendedores';

    $html = '';
    $html .= '<div class="historico-chart-side">';
    $html .= '<h3>Gráfico del Mes</h3>';
    $html .= '<p class="historico-sub">' . htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8') . ' · Corte ' . htmlspecialchars(date('d/m/Y', strtotime($selectedDateStr)), ENT_QUOTES, 'UTF-8') . ' · ' . $scopeLabel . '</p>';
    $html .= '<div class="resumen-chart-legend resumen-chart-legend-vertical"><span class="legend-item is-sales">Ventas</span><span class="legend-item is-quota">Cuota</span></div>';
    $html .= '<div class="chart-hover-summary" data-default-date="' . htmlspecialchars($selectedDateLabel, ENT_QUOTES, 'UTF-8') . '" data-default-sale="' . htmlspecialchars(number_format($selectedSale, 2, '.', ','), ENT_QUOTES, 'UTF-8') . '" data-default-quota="' . htmlspecialchars(number_format($selectedQuota, 2, '.', ','), ENT_QUOTES, 'UTF-8') . '" data-default-avance="' . $selectedPct . '%" data-default-faltante="' . htmlspecialchars(number_format($selectedFaltante, 2, '.', ','), ENT_QUOTES, 'UTF-8') . '">';
    $html .= '<div class="chart-tooltip-date">' . htmlspecialchars($selectedDateLabel, ENT_QUOTES, 'UTF-8') . '</div>';
    $html .= '<div class="chart-tooltip-grid">';
    $html .= '<div class="chart-tooltip-item"><span class="chart-tooltip-label">Venta</span><strong class="chart-tooltip-value">S/ ' . htmlspecialchars(number_format($selectedSale, 2, '.', ','), ENT_QUOTES, 'UTF-8') . '</strong></div>';
    $html .= '<div class="chart-tooltip-item"><span class="chart-tooltip-label">Cuota</span><strong class="chart-tooltip-value">S/ ' . htmlspecialchars(number_format($selectedQuota, 2, '.', ','), ENT_QUOTES, 'UTF-8') . '</strong></div>';
    $html .= '<div class="chart-tooltip-item"><span class="chart-tooltip-label">Avance</span><strong class="chart-tooltip-value">' . $selectedPct . '%</strong></div>';
    $html .= '<div class="chart-tooltip-item"><span class="chart-tooltip-label">Faltante</span><strong class="chart-tooltip-value">S/ ' . htmlspecialchars(number_format($selectedFaltante, 2, '.', ','), ENT_QUOTES, 'UTF-8') . '</strong></div>';
    $html .= '</div></div>';
    $html .= '<svg class="resumen-chart-svg resumen-chart-svg-vertical" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="Grafico vertical mensual de ventas y cuota diaria">';
    $html .= '<line x1="' . $left . '" y1="' . $top . '" x2="' . $left . '" y2="' . ($top + $plotHeight) . '" class="chart-axis" />';
    $html .= '<line x1="' . $left . '" y1="' . ($top + $plotHeight) . '" x2="' . $xMax . '" y2="' . ($top + $plotHeight) . '" class="chart-axis" />';
    $html .= '<line x1="' . $left . '" y1="' . $top . '" x2="' . $left . '" y2="' . ($top + $plotHeight) . '" class="chart-grid" />';
    $html .= '<line x1="' . number_format($xMid, 2, '.', '') . '" y1="' . $top . '" x2="' . number_format($xMid, 2, '.', '') . '" y2="' . ($top + $plotHeight) . '" class="chart-grid" />';
    $html .= '<line x1="' . $xMax . '" y1="' . $top . '" x2="' . $xMax . '" y2="' . ($top + $plotHeight) . '" class="chart-grid" />';
    if ($selectedY !== null) {
        $html .= '<line x1="' . $left . '" y1="' . number_format($selectedY, 2, '.', '') . '" x2="' . $xMax . '" y2="' . number_format($selectedY, 2, '.', '') . '" class="chart-focus" />';
    }
    $html .= '<text x="' . $left . '" y="14" text-anchor="start" class="chart-y-label">S/ ' . htmlspecialchars(number_format($scaleMin, 0, '.', ','), ENT_QUOTES, 'UTF-8') . '</text>';
    $html .= '<text x="' . number_format($xMid, 2, '.', '') . '" y="14" text-anchor="middle" class="chart-y-label">S/ ' . htmlspecialchars(number_format(($scaleMin + $scaleMax) / 2, 0, '.', ','), ENT_QUOTES, 'UTF-8') . '</text>';
    $html .= '<text x="' . $xMax . '" y="14" text-anchor="end" class="chart-y-label">S/ ' . htmlspecialchars(number_format($scaleMax, 0, '.', ','), ENT_QUOTES, 'UTF-8') . '</text>';
    foreach ($chartDates as $index => $dayDate) {
        $y = $top + ($rowStep * $index);
        $daySale = $dailySalesByDay[$dayDate];
        $dayQuota = $dailyQuotaByDay[$dayDate];
        $dayPctRaw = ((float)$dayQuota > 0) ? ((((float)($daySale ?? 0.0)) / (float)$dayQuota) * 100) : 0;
        $dayPct = ($dayPctRaw < 100) ? floor($dayPctRaw) : round($dayPctRaw);
        $dayFaltante = max(0.0, (float)$dayQuota - (float)($daySale ?? 0.0));
        $bandY = $index === 0 ? ($top - ($rowStep / 2)) : ($y - ($rowStep / 2));
        $bandHeight = ($index === count($chartDates) - 1) ? (($top + $plotHeight) - $bandY) : $rowStep;
        $html .= '<rect x="' . $left . '" y="' . number_format($bandY, 2, '.', '') . '" width="' . $plotWidth . '" height="' . number_format($bandHeight, 2, '.', '') . '" class="chart-hover-band" data-date="' . htmlspecialchars(date('d/m/Y', strtotime($dayDate)), ENT_QUOTES, 'UTF-8') . '" data-sale="' . htmlspecialchars(number_format((float)($daySale ?? 0.0), 2, '.', ','), ENT_QUOTES, 'UTF-8') . '" data-quota="' . htmlspecialchars(number_format((float)$dayQuota, 2, '.', ','), ENT_QUOTES, 'UTF-8') . '" data-avance="' . $dayPct . '%" data-faltante="' . htmlspecialchars(number_format($dayFaltante, 2, '.', ','), ENT_QUOTES, 'UTF-8') . '" aria-label="' . htmlspecialchars(date('d/m/Y', strtotime($dayDate)) . ' Venta ' . number_format((float)($daySale ?? 0.0), 2, '.', ',') . ' Cuota ' . number_format((float)$dayQuota, 2, '.', ',') . ' Avance ' . $dayPct . '% Faltante ' . number_format($dayFaltante, 2, '.', ','), ENT_QUOTES, 'UTF-8') . '"></rect>';
        $html .= '<text x="36" y="' . number_format($y + 3, 2, '.', '') . '" text-anchor="end" class="chart-x-label">' . htmlspecialchars(date('j', strtotime($dayDate)), ENT_QUOTES, 'UTF-8') . '</text>';
    }
    if ($quotaPath !== '') $html .= '<path d="' . htmlspecialchars($quotaPath, ENT_QUOTES, 'UTF-8') . '" class="chart-line chart-line-quota" />';
    if ($salesPath !== '') $html .= '<path d="' . htmlspecialchars($salesPath, ENT_QUOTES, 'UTF-8') . '" class="chart-line chart-line-sales" />';
    foreach ($chartDates as $index => $dayDate) {
        $y = $top + ($rowStep * $index);
        $quotaX = scaleVerticalChartValue((float)$quotaValues[$index], $scaleMin, $scaleMax, $left, $plotWidth);
        $html .= '<circle cx="' . number_format($quotaX, 2, '.', '') . '" cy="' . number_format($y, 2, '.', '') . '" r="2.5" class="chart-point chart-point-quota" />';
        $salesValue = $salesValues[$index];
        if ($salesValue !== null) {
            $salesX = scaleVerticalChartValue((float)$salesValue, $scaleMin, $scaleMax, $left, $plotWidth);
            $html .= '<circle cx="' . number_format($salesX, 2, '.', '') . '" cy="' . number_format($y, 2, '.', '') . '" r="3.1" class="chart-point chart-point-sales" />';
        }
        $html .= '<circle cx="' . $left . '" cy="' . number_format($y, 2, '.', '') . '" r="1.8" class="chart-tick" />';
    }
    $html .= '</svg>';
    $html .= '</div>';

    return $html;
}

/**
 * Cuenta días hábiles de facturación (lunes a sábado) entre dos fechas inclusive.
 */
function countBusinessDays(string $startDate, string $endDate): int {
    global $mysqli;
    return countConfiguredBusinessDays($mysqli, $startDate, $endDate);
}

function renderResumenMoneyMetric(string $label, float $igvValue, float $biValue): string {
    return '<div class="hm"><small>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</small><b class="cuota-mes-money" data-igv="'
        . htmlspecialchars(number_format($igvValue, 2, '.', ''), ENT_QUOTES, 'UTF-8')
        . '" data-bi="'
        . htmlspecialchars(number_format($biValue, 2, '.', ''), ENT_QUOTES, 'UTF-8')
        . '">S/ ' . number_format($igvValue, 2, '.', ',') . '</b></div>';
}

/** Devuelve true si la fecha es día hábil de venta (lunes a sábado). */
function isBusinessDay(string $date): bool {
    global $mysqli;
    return isConfiguredBusinessDay($mysqli, $date);
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
$ventaPositivaMes = 0.0;
$montoNegativosQ1 = 0.0;
$montoNegativosQ2 = 0.0;
$ventaMensualGlobalBi = 0.0;
$ventaPositivaMesBi = 0.0;
$montoNegativosQ1Bi = 0.0;
$montoNegativosQ2Bi = 0.0;

// Nueva regla para proyección mensual:
// 1) Promediar ventas positivas del mes entre días hábiles transcurridos (lunes a sábado desde el día 1).
// 2) Multiplicar por los días hábiles del mes completo (sin domingos).
// 3) Proyectar notas de crédito por quincena y restarlas.
// 4) Excluir anulados (anulado = 1).
$fechaHoy = date('Y-m-d');
$fechaLimite = $fecha;
if ($fechaLimite < $mesIni) { $fechaLimite = $mesIni; }
if ($fechaLimite > $mesFin) { $fechaLimite = $mesFin; }
if ($fechaLimite > $fechaHoy) { $fechaLimite = $fechaHoy; }

$baseAmountExpr = 'COALESCE(valorventa, CASE WHEN total IS NOT NULL THEN (total / 1.18) ELSE 0 END)';

$qvm = $mysqli->prepare("SELECT
    COALESCE(SUM(total), 0) AS venta_neta,
    COALESCE(SUM(CASE WHEN total > 0 THEN total ELSE 0 END), 0) AS venta_positiva,
    COALESCE(SUM(CASE WHEN total < 0 AND DAY(fecha) BETWEEN 1 AND 15 THEN ABS(total) ELSE 0 END), 0) AS negativos_q1,
    COALESCE(SUM(CASE WHEN total < 0 AND DAY(fecha) >= 16 THEN ABS(total) ELSE 0 END), 0) AS negativos_q2,
    COALESCE(SUM($baseAmountExpr), 0) AS venta_neta_bi,
    COALESCE(SUM(CASE WHEN $baseAmountExpr > 0 THEN $baseAmountExpr ELSE 0 END), 0) AS venta_positiva_bi,
    COALESCE(SUM(CASE WHEN $baseAmountExpr < 0 AND DAY(fecha) BETWEEN 1 AND 15 THEN ABS($baseAmountExpr) ELSE 0 END), 0) AS negativos_q1_bi,
    COALESCE(SUM(CASE WHEN $baseAmountExpr < 0 AND DAY(fecha) >= 16 THEN ABS($baseAmountExpr) ELSE 0 END), 0) AS negativos_q2_bi
    FROM cubo_de_ventas_resumen
    WHERE fecha BETWEEN ? AND ?
      AND COALESCE(anulado, 0) <> 1");
if ($qvm) {
    $qvm->bind_param('ss', $mesIni, $fechaLimite);
    $qvm->execute();
    $rvm = $qvm->get_result();
    if ($rvm && ($rowm = $rvm->fetch_assoc())) {
        $ventaMensualGlobal = (float)$rowm['venta_neta'];
        $ventaPositivaMes = (float)$rowm['venta_positiva'];
        $montoNegativosQ1 = (float)$rowm['negativos_q1'];
        $montoNegativosQ2 = (float)$rowm['negativos_q2'];
        $ventaMensualGlobalBi = (float)$rowm['venta_neta_bi'];
        $ventaPositivaMesBi = (float)$rowm['venta_positiva_bi'];
        $montoNegativosQ1Bi = (float)$rowm['negativos_q1_bi'];
        $montoNegativosQ2Bi = (float)$rowm['negativos_q2_bi'];
    }
    $qvm->close();
}

$faltanteMensualGlobal = max(0, $cuotaMensualGlobal - $ventaMensualGlobal);
$cuotaMensualGlobalBi = $cuotaMensualGlobal / 1.18;
$faltanteMensualGlobalBi = max(0, $cuotaMensualGlobalBi - $ventaMensualGlobalBi);

// Días hábiles del mes: lunes a sábado desde el día 1 hasta fin de mes, sin domingos.
$diasHabilesTranscurridos = countBusinessDays($mesIni, $fechaLimite);
$diasHabilesMes = countBusinessDays($mesIni, $mesFin);
$diasHabilesFaltantes = max(0, $diasHabilesMes - $diasHabilesTranscurridos);

$quincena1Fin = date('Y-m-15', strtotime($fecha));
$quincena2Inicio = date('Y-m-16', strtotime($fecha));
$quincena2Fin = $mesFin;

$diasHabilesQ1Transcurridos = countBusinessDays($mesIni, min($fechaLimite, $quincena1Fin));
$diasHabilesQ1Total = countBusinessDays($mesIni, $quincena1Fin);
$diasHabilesQ2Transcurridos = 0;
$diasHabilesQ2Total = countBusinessDays($quincena2Inicio, $quincena2Fin);
if ($fechaLimite >= $quincena2Inicio) {
    $diasHabilesQ2Transcurridos = countBusinessDays($quincena2Inicio, $fechaLimite);
}

$proyeccionVentaPositiva = 0.0;
if ($diasHabilesTranscurridos > 0 && $diasHabilesMes > 0) {
    $proyeccionVentaPositiva = ($ventaPositivaMes / $diasHabilesTranscurridos) * $diasHabilesMes;
}

$proyeccionVentaPositivaBi = 0.0;
if ($diasHabilesTranscurridos > 0 && $diasHabilesMes > 0) {
    $proyeccionVentaPositivaBi = ($ventaPositivaMesBi / $diasHabilesTranscurridos) * $diasHabilesMes;
}

$proyeccionNegativosQ1 = 0.0;
if ($montoNegativosQ1 > 0 && $diasHabilesQ1Transcurridos > 0 && $diasHabilesQ1Total > 0) {
    $proyeccionNegativosQ1 = ($montoNegativosQ1 / $diasHabilesQ1Transcurridos) * $diasHabilesQ1Total;
}

$proyeccionNegativosQ1Bi = 0.0;
if ($montoNegativosQ1Bi > 0 && $diasHabilesQ1Transcurridos > 0 && $diasHabilesQ1Total > 0) {
    $proyeccionNegativosQ1Bi = ($montoNegativosQ1Bi / $diasHabilesQ1Transcurridos) * $diasHabilesQ1Total;
}

$proyeccionNegativosQ2 = 0.0;
if ($montoNegativosQ2 > 0 && $diasHabilesQ2Transcurridos > 0 && $diasHabilesQ2Total > 0) {
    $proyeccionNegativosQ2 = ($montoNegativosQ2 / $diasHabilesQ2Transcurridos) * $diasHabilesQ2Total;
}

$proyeccionNegativosQ2Bi = 0.0;
if ($montoNegativosQ2Bi > 0 && $diasHabilesQ2Transcurridos > 0 && $diasHabilesQ2Total > 0) {
    $proyeccionNegativosQ2Bi = ($montoNegativosQ2Bi / $diasHabilesQ2Transcurridos) * $diasHabilesQ2Total;
}

$proyeccionMensual = $proyeccionVentaPositiva - $proyeccionNegativosQ1 - $proyeccionNegativosQ2;
$proyeccionMensualBi = $proyeccionVentaPositivaBi - $proyeccionNegativosQ1Bi - $proyeccionNegativosQ2Bi;
$desvProyCuota = $proyeccionMensual - $cuotaMensualGlobal;
$desvProyCuotaBi = $proyeccionMensualBi - $cuotaMensualGlobalBi;
$pctMensualRaw = $cuotaMensualGlobal > 0 ? (($ventaMensualGlobal / $cuotaMensualGlobal) * 100) : 0;
$pctMensual = ($pctMensualRaw < 100) ? floor($pctMensualRaw) : round($pctMensualRaw);

echo '<div class="cuota-mes-side">';
echo '<div class="cuota-mes-card-inner">';
echo '<div class="cuota-mes-face is-front">';
echo '<div class="cuota-mes-corner-actions">';
echo '<button type="button" class="cuota-mes-flip-hint" aria-label="Voltear tarjeta">flip</button>';
echo '<label class="cuota-mes-bi-hint" aria-label="Mostrar base imponible"><input type="checkbox" class="cuota-mes-bi-check"><span>B.I</span></label>';
echo '</div>';
echo '<h3>Cuota del Mes</h3>';
echo '<p class="historico-sub">' . htmlspecialchars(($meses[$mesSel] ?? 'Mes') . ' ' . $anioSel) . '</p>';
echo '<p class="historico-sub">Días hábiles: ' . $diasHabilesTranscurridos . ' de ' . $diasHabilesMes . ' (faltan ' . $diasHabilesFaltantes . ')</p>';
echo '<div class="historico-metrics-grid">';
echo renderResumenMoneyMetric('Cuota Mes', $cuotaMensualGlobal, $cuotaMensualGlobalBi);
echo renderResumenMoneyMetric('Venta Mes', $ventaMensualGlobal, $ventaMensualGlobalBi);
echo renderResumenMoneyMetric('Faltante', $faltanteMensualGlobal, $faltanteMensualGlobalBi);
echo '<div class="hm"><small>Avance</small><b>' . $pctMensual . '%</b></div>';
echo renderResumenMoneyMetric('Proyección Mes', $proyeccionMensual, $proyeccionMensualBi);
echo renderResumenMoneyMetric('Desv. Proy vs Cuota', $desvProyCuota, $desvProyCuotaBi);
echo '</div>';
echo renderResumenHistoricoProgress((float)$pctMensual, 'historico-progress');
echo '<div class="cuota-mes-face-note">Doble clic en un espacio en blanco para ver el desglose.</div>';
echo '</div>';
echo '<div class="cuota-mes-face is-back">';
echo '<div class="cuota-mes-corner-actions">';
echo '<button type="button" class="cuota-mes-flip-hint" aria-label="Voltear tarjeta">flip</button>';
echo '<label class="cuota-mes-bi-hint" aria-label="Mostrar base imponible"><input type="checkbox" class="cuota-mes-bi-check"><span>B.I</span></label>';
echo '</div>';
echo '<h3>Desglose de Proyección</h3>';
echo '<p class="historico-sub">' . htmlspecialchars(($meses[$mesSel] ?? 'Mes') . ' ' . $anioSel) . '</p>';
echo '<div class="historico-metrics-grid cuota-mes-breakdown">';
echo renderResumenMoneyMetric('Proy. ventas positivas', $proyeccionVentaPositiva, $proyeccionVentaPositivaBi);
echo renderResumenMoneyMetric('Proy. NC Q1', $proyeccionNegativosQ1, $proyeccionNegativosQ1Bi);
echo renderResumenMoneyMetric('Proy. NC Q2', $proyeccionNegativosQ2, $proyeccionNegativosQ2Bi);
echo renderResumenMoneyMetric('Proyección neta', $proyeccionMensual, $proyeccionMensualBi);
echo '</div>';
echo '<p class="historico-sub">Proyección neta = Proy. ventas positivas - Proy. NC Q1 - Proy. NC Q2</p>';
echo '<div class="cuota-mes-face-note">Doble clic en un espacio en blanco para volver.</div>';
echo '</div>';
echo '</div>';
echo '</div>';

echo '<div class="historico-side">';
echo '<h3>Histórico 4 semanas</h3>';
echo '<p class="historico-sub">' . htmlspecialchars($diaBase) . 's recientes' . ($supervisor !== '' ? (' · ' . htmlspecialchars($supervisor)) : '') . '</p>';
echo '<div class="historico-list">';

foreach ($fechas as $f) {
    $tot = totalizarFecha($mysqli, $f, $supervisor, $vd_supervisor);
    $pct = (int)$tot['avance'];

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
    echo renderResumenHistoricoProgress((float)$pct, 'historico-progress');
    echo '</div>';
}

echo '</div>';
echo '</div>';

echo renderResumenVerticalMonthChart($mysqli, $fecha, $supervisor, $vd_supervisor);

$mysqli->close();
