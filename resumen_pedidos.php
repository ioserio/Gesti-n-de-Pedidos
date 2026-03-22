<?php
require_once __DIR__ . '/require_login.php';
require_once __DIR__ . '/init.php';
require_once 'conexion.php';

$fecha = isset($_GET['fecha']) ? $_GET['fecha'] : date('Y-m-d');
$supervisor = isset($_GET['supervisor']) ? $_GET['supervisor'] : '';
$groupSup = isset($_GET['group_supervisor']) && ($_GET['group_supervisor'] === '1' || strtolower($_GET['group_supervisor']) === 'true');

// Relación VD => SUPERVISOR
$vd_supervisor = [
    '011'=>'CARLOS','016'=>'CARLOS','023'=>'JOSE','026'=>'JESUS','028'=>'CARLOS','029'=>'CARLOS','030'=>'CARLOS',
    '102'=>'JESUS','104'=>'JOSE','106'=>'JESUS','108'=>'JOSE','110'=>'JESUS','113'=>'JESUS','114'=>'JESUS','118'=>'JESUS',
    '997'=>'FRANCISCO','777'=>'OFICINA','999'=>'OFICINA','004'=>'JOSE','005'=>'CARLOS','012'=>'JOSE','014'=>'JESUS',
    '018'=>'JESUS','024'=>'FRANCISCO','025'=>'FRANCISCO','027'=>'CARLOS','101'=>'JOSE','103'=>'JOSE','105'=>'CARLOS',
    '107'=>'JOSE','109'=>'JESUS','606'=>'FRANCISCO','607'=>'FRANCISCO','001'=>'JOSE','002'=>'JOSE','003'=>'JOSE',
    '007'=>'JOSE','008'=>'JESUS','009'=>'JESUS','010'=>'JOSE','013'=>'JESUS','021'=>'CARLOS','022'=>'CARLOS',
    '115'=>'CARLOS','116'=>'CARLOS','119'=>'CARLOS','604'=>'FRANCISCO','605'=>'FRANCISCO'
];

function vendorCodeVariants(string $vendorCode): array {
    $raw = trim($vendorCode);
    $noZeros = ltrim($raw, '0');
    if ($noZeros === '') {
        $noZeros = '0';
    }
    $padded = str_pad($noZeros, 3, '0', STR_PAD_LEFT);
    return [$raw, $noZeros, $padded];
}

function resolveSupervisorForVendor(string $vendorCode, array $vdSupervisor): string {
    [$raw, $noZeros, $padded] = vendorCodeVariants($vendorCode);
    if (isset($vdSupervisor[$raw])) return $vdSupervisor[$raw];
    if (isset($vdSupervisor[$noZeros])) return $vdSupervisor[$noZeros];
    if (isset($vdSupervisor[$padded])) return $vdSupervisor[$padded];
    return '';
}

function resolveVendorQuota(array $cuotas, string $vendorCode): float {
    [$raw, $noZeros, $padded] = vendorCodeVariants($vendorCode);
    if (isset($cuotas[$raw])) return (float)$cuotas[$raw];
    if (isset($cuotas[$noZeros])) return (float)$cuotas[$noZeros];
    if (isset($cuotas[$padded])) return (float)$cuotas[$padded];
    return 0.0;
}

function loadCuotasForDate(mysqli $mysqli, string $fecha): array {
    static $cache = [];
    if (isset($cache[$fecha])) {
        return $cache[$fecha];
    }

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

    $cache[$fecha] = $cuotas;
    return $cuotas;
}

function buildResumenChartPath(array $values, float $left, float $top, float $width, float $height, float $maxValue): string {
    $points = count($values);
    if ($points === 0) {
        return '';
    }
    if ($maxValue <= 0) {
        $maxValue = 1;
    }

    $stepX = $points > 1 ? ($width / ($points - 1)) : 0;
    $path = '';
    $started = false;
    foreach ($values as $index => $value) {
        if ($value === null) {
            $started = false;
            continue;
        }
        $x = $left + ($stepX * $index);
        $y = $top + $height - (($value / $maxValue) * $height);
        $path .= ($started ? ' L ' : 'M ') . number_format($x, 2, '.', '') . ' ' . number_format($y, 2, '.', '');
        $started = true;
    }
    return trim($path);
}

function renderResumenMonthChart(mysqli $mysqli, string $fecha, string $supervisor, array $vdSupervisor): string {
    try {
        $selectedDate = new DateTimeImmutable($fecha);
    } catch (Throwable $e) {
        return '';
    }

    $monthStart = $selectedDate->modify('first day of this month');
    $monthEnd = $selectedDate->modify('last day of this month');
    $monthStartStr = $monthStart->format('Y-m-d');
    $monthEndStr = $monthEnd->format('Y-m-d');
    $selectedDateStr = $selectedDate->format('Y-m-d');

    $salesByDay = [];
    $stmtMonth = $mysqli->prepare("SELECT Fecha, Cod_Vendedor, SUM(CAST(REPLACE(Total_IGV, ',', '') AS DECIMAL(12,2))) AS total_igv FROM pedidos_x_dia WHERE Fecha BETWEEN ? AND ? GROUP BY Fecha, Cod_Vendedor ORDER BY Fecha ASC, Cod_Vendedor ASC");
    if ($stmtMonth) {
        $stmtMonth->bind_param('ss', $monthStartStr, $monthEndStr);
        $stmtMonth->execute();
        $resultMonth = $stmtMonth->get_result();
        while ($row = $resultMonth->fetch_assoc()) {
            $vendorCode = (string)$row['Cod_Vendedor'];
            $vendorSupervisor = resolveSupervisorForVendor($vendorCode, $vdSupervisor);
            if ($supervisor !== '' && $vendorSupervisor !== $supervisor) {
                continue;
            }
            $day = (string)$row['Fecha'];
            if (!isset($salesByDay[$day])) {
                $salesByDay[$day] = 0.0;
            }
            $salesByDay[$day] += (float)$row['total_igv'];
        }
        $stmtMonth->close();
    }

    $chartDates = [];
    $days = [];
    $salesValues = [];
    $quotaValues = [];
    $dailyQuotaByDay = [];
    $dailySalesByDay = [];
    $maxValue = 0.0;
    $cursor = $monthStart;

    while ($cursor <= $monthEnd) {
        $dayStr = $cursor->format('Y-m-d');

        $dailyQuota = 0.0;
        foreach (loadCuotasForDate($mysqli, $dayStr) as $vendorCode => $quotaValue) {
            $vendorSupervisor = resolveSupervisorForVendor((string)$vendorCode, $vdSupervisor);
            if ($supervisor !== '' && $vendorSupervisor !== $supervisor) {
                continue;
            }
            $dailyQuota += (float)$quotaValue;
        }

        $dailySale = ($dayStr <= $selectedDateStr) ? (float)($salesByDay[$dayStr] ?? 0.0) : null;
        $dailyQuotaByDay[$dayStr] = $dailyQuota;
        $dailySalesByDay[$dayStr] = $dailySale;

        if ((int)$cursor->format('N') === 7) {
            $cursor = $cursor->modify('+1 day');
            continue;
        }

        $chartDates[] = $dayStr;
        $days[] = $cursor->format('j');
        $quotaValues[] = $dailyQuota;
        $salesValues[] = $dailySale;
        $maxValue = max($maxValue, $dailyQuota, $dailySale ?? 0.0);
        $cursor = $cursor->modify('+1 day');
    }

    if (empty($chartDates)) {
        return '';
    }

    $selectedSale = (float)($dailySalesByDay[$selectedDateStr] ?? 0.0);
    $selectedQuota = (float)($dailyQuotaByDay[$selectedDateStr] ?? 0.0);
    $selectedPctRaw = $selectedQuota > 0 ? (($selectedSale / $selectedQuota) * 100) : 0;
    $selectedPct = ($selectedPctRaw < 100) ? floor($selectedPctRaw) : round($selectedPctRaw);

    $width = 860;
    $height = 236;
    $left = 42;
    $top = 16;
    $right = 16;
    $bottom = 42;
    $plotWidth = $width - $left - $right;
    $plotHeight = $height - $top - $bottom;
    $stepX = count($chartDates) > 1 ? ($plotWidth / (count($chartDates) - 1)) : 0;
    $selectedIndex = array_search($selectedDateStr, $chartDates, true);
    $selectedX = ($selectedIndex === false) ? null : ($left + ($stepX * $selectedIndex));
    $quotaPath = buildResumenChartPath($quotaValues, $left, $top, $plotWidth, $plotHeight, $maxValue);
    $salesPath = buildResumenChartPath($salesValues, $left, $top, $plotWidth, $plotHeight, $maxValue);
    $yTopLabel = 'S/ ' . number_format($maxValue, 0, '.', ',');
    $yMidLabel = 'S/ ' . number_format($maxValue / 2, 0, '.', ',');
    $monthNames = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
    ];
    $monthLabel = ($monthNames[(int)$selectedDate->format('n')] ?? $selectedDate->format('F')) . ' ' . $selectedDate->format('Y');
    $scopeLabel = $supervisor !== '' ? ('Supervisor ' . htmlspecialchars($supervisor, ENT_QUOTES, 'UTF-8')) : 'Todos los vendedores';

    $html = '<div class="resumen-chart-card">';
    $html .= '<div class="resumen-chart-head">';
    $html .= '<div><h3>Ventas vs Cuota diaria</h3><p>' . htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8') . ' · Corte ' . htmlspecialchars(date('d/m/Y', strtotime($selectedDateStr)), ENT_QUOTES, 'UTF-8') . ' · ' . $scopeLabel . '</p></div>';
    $html .= '<div class="resumen-chart-legend"><span class="legend-item is-sales">Ventas</span><span class="legend-item is-quota">Cuota</span></div>';
    $html .= '</div>';
    $html .= '<svg class="resumen-chart-svg" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="Grafico mensual de ventas y cuota diaria">';
    $html .= '<line x1="' . $left . '" y1="' . ($top + $plotHeight) . '" x2="' . ($left + $plotWidth) . '" y2="' . ($top + $plotHeight) . '" class="chart-axis" />';
    $html .= '<line x1="' . $left . '" y1="' . $top . '" x2="' . $left . '" y2="' . ($top + $plotHeight) . '" class="chart-axis" />';
    $html .= '<line x1="' . $left . '" y1="' . $top . '" x2="' . ($left + $plotWidth) . '" y2="' . $top . '" class="chart-grid" />';
    $html .= '<line x1="' . $left . '" y1="' . ($top + ($plotHeight / 2)) . '" x2="' . ($left + $plotWidth) . '" y2="' . ($top + ($plotHeight / 2)) . '" class="chart-grid" />';
    $html .= '<line x1="' . $left . '" y1="' . ($top + $plotHeight) . '" x2="' . ($left + $plotWidth) . '" y2="' . ($top + $plotHeight) . '" class="chart-grid" />';
    if ($selectedX !== null) {
        $html .= '<line x1="' . number_format($selectedX, 2, '.', '') . '" y1="' . $top . '" x2="' . number_format($selectedX, 2, '.', '') . '" y2="' . ($top + $plotHeight) . '" class="chart-focus" />';
    }
    $html .= '<text x="4" y="' . ($top + 4) . '" class="chart-y-label">' . htmlspecialchars($yTopLabel, ENT_QUOTES, 'UTF-8') . '</text>';
    $html .= '<text x="4" y="' . ($top + ($plotHeight / 2) + 4) . '" class="chart-y-label">' . htmlspecialchars($yMidLabel, ENT_QUOTES, 'UTF-8') . '</text>';
    $html .= '<text x="18" y="' . ($top + $plotHeight + 4) . '" class="chart-y-label">S/ 0</text>';
    foreach ($chartDates as $index => $dayDate) {
        $dayNumber = $days[$index];
        $dayLabel = date('d/m/Y', strtotime($dayDate));
        $daySale = $dailySalesByDay[$dayDate];
        $dayQuota = $dailyQuotaByDay[$dayDate];
        $dayPctRaw = ((float)$dayQuota > 0) ? ((((float)($daySale ?? 0.0)) / (float)$dayQuota) * 100) : 0;
        $dayPct = ($dayPctRaw < 100) ? floor($dayPctRaw) : round($dayPctRaw);
        $x = $left + ($stepX * $index);
        $bandX = $index === 0 ? $left : $x - ($stepX / 2);
        $bandWidth = count($chartDates) === 1 ? $plotWidth : ($index === count($chartDates) - 1 ? ($left + $plotWidth) - $bandX : $stepX);
        $tooltip = $dayLabel
            . ' · Venta: S/ ' . number_format((float)($daySale ?? 0.0), 2, '.', ',')
            . ' · Cuota: S/ ' . number_format((float)$dayQuota, 2, '.', ',')
            . ' · Avance: ' . $dayPct . '%';
        $html .= '<rect x="' . number_format($bandX, 2, '.', '') . '" y="' . $top . '" width="' . number_format($bandWidth, 2, '.', '') . '" height="' . $plotHeight . '" class="chart-hover-band"><title>' . htmlspecialchars($tooltip, ENT_QUOTES, 'UTF-8') . '</title></rect>';
    }
    if ($quotaPath !== '') {
        $html .= '<path d="' . htmlspecialchars($quotaPath, ENT_QUOTES, 'UTF-8') . '" class="chart-line chart-line-quota" />';
    }
    if ($salesPath !== '') {
        $html .= '<path d="' . htmlspecialchars($salesPath, ENT_QUOTES, 'UTF-8') . '" class="chart-line chart-line-sales" />';
    }
    foreach ($chartDates as $index => $dayDate) {
        $dayNumber = $days[$index];
        $x = $left + ($stepX * $index);
        $quotaValue = (float)$quotaValues[$index];
        $quotaY = $top + $plotHeight - (($quotaValue / max($maxValue, 1)) * $plotHeight);
        $salesValue = $salesValues[$index];
        if ($salesValue !== null) {
            $salesY = $top + $plotHeight - ((((float)$salesValue) / max($maxValue, 1)) * $plotHeight);
            $html .= '<circle cx="' . number_format($x, 2, '.', '') . '" cy="' . number_format($salesY, 2, '.', '') . '" r="3.2" class="chart-point chart-point-sales" />';
        }
        $html .= '<circle cx="' . number_format($x, 2, '.', '') . '" cy="' . number_format($quotaY, 2, '.', '') . '" r="2.6" class="chart-point chart-point-quota" />';
        $html .= '<circle cx="' . number_format($x, 2, '.', '') . '" cy="' . ($top + $plotHeight) . '" r="1.8" class="chart-tick" />';
        $html .= '<text x="' . number_format($x, 2, '.', '') . '" y="' . ($top + $plotHeight + 20) . '" text-anchor="middle" class="chart-x-label">' . htmlspecialchars((string)$dayNumber, ENT_QUOTES, 'UTF-8') . '</text>';
    }
    $html .= '</svg>';
    $html .= '<div class="resumen-chart-foot">';
    $html .= '<span class="chart-metric"><small>Venta día</small><b>S/ ' . number_format($selectedSale, 2, '.', ',') . '</b></span>';
    $html .= '<span class="chart-metric"><small>Cuota día</small><b>S/ ' . number_format($selectedQuota, 2, '.', ',') . '</b></span>';
    $html .= '<span class="chart-metric"><small>Avance día</small><b>' . $selectedPct . '%</b></span>';
    $html .= '</div>';
    $html .= '</div>';

    return $html;
}

function resumenProgressClass(float $avance): string {
    if ($avance < 40) return 'seg-progress-fill is-low';
    if ($avance < 70) return 'seg-progress-fill is-mid';
    if ($avance < 100) return 'seg-progress-fill is-good';
    return 'seg-progress-fill is-top';
}

function renderResumenProgress(float $avance, string $extraClass = ''): string {
    $avanceRedondeado = round($avance, 1);
    $label = rtrim(rtrim(number_format($avanceRedondeado, 1, '.', ''), '0'), '.');
    if ($label === '') $label = '0';
    $label .= '%';
    $width = max(0, min(100, $avanceRedondeado));
    $class = trim('seg-progress ' . $extraClass);

    return '<div class="' . $class . '" aria-label="Avance ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">' 
        . '<div class="' . resumenProgressClass($avanceRedondeado) . '" style="width:' . number_format($width, 1, '.', '') . '%"></div>'
        . '<span class="seg-progress-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>'
        . '</div>';
}

$sql = "SELECT Cod_Vendedor, Nom_Vendedor, COUNT(*) AS ctd_pedidos, SUM(CAST(REPLACE(Total_IGV, ',', '') AS DECIMAL(12,2))) AS total_igv FROM pedidos_x_dia WHERE Fecha = ? GROUP BY Cod_Vendedor, Nom_Vendedor ORDER BY Cod_Vendedor ASC";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param('s', $fecha);
$stmt->execute();
$result = $stmt->get_result();

$cuotas = loadCuotasForDate($mysqli, $fecha);
if ($result->num_rows > 0) {
    $total_pedidos = 0;
    $total_monto = 0;
    $total_cuota = 0.0;
    $total_faltante = 0.0;
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $vdRaw = trim((string)$row['Cod_Vendedor']);
        [, $vdNoZeros, $vdPadded3] = vendorCodeVariants($vdRaw);

        $sup = resolveSupervisorForVendor($vdRaw, $vd_supervisor);
        $row['Supervisor'] = $sup;
        if ($supervisor && $row['Supervisor'] !== $supervisor) continue;
        $cuotaVal = resolveVendorQuota($cuotas, $vdRaw);
        $row['CuotaVal'] = $cuotaVal;

        $total_pedidos += intval($row['ctd_pedidos']);
        $total_monto += floatval($row['total_igv']);
        $total_cuota += $cuotaVal;
        $total_faltante += max(0, $cuotaVal - (float)$row['total_igv']);
        $rows[] = $row;
    }

    // Faltante total NETO: debe considerar toda la venta (incluida OFICINA)
    // y compensar sobrecumplimientos entre vendedores/mesas.
    // Formula: max(0, cuota_total - monto_total).
    $total_faltante_view = max(0, $total_cuota - $total_monto);

    // Construir grupos para la vista por mesa.
    $groups = [];
    if ($groupSup) {
        foreach ($rows as $row) {
            $supKey = $row['Supervisor'] ?: 'SIN SUPERVISOR';
            if (!isset($groups[$supKey])) {
                $groups[$supKey] = [
                    'Supervisor' => $supKey,
                    'ctd_pedidos' => 0,
                    'total_igv' => 0.0,
                    'cuota' => 0.0,
                    'faltante' => 0.0,
                ];
            }
            $groups[$supKey]['ctd_pedidos'] += intval($row['ctd_pedidos']);
            $groups[$supKey]['total_igv'] += floatval($row['total_igv']);
            $groups[$supKey]['cuota'] += floatval(isset($row['CuotaVal']) ? $row['CuotaVal'] : 0.0);
        }
        foreach ($groups as $k => $g) {
            $falt = max(0, (float)$g['cuota'] - (float)$g['total_igv']);
            $groups[$k]['faltante'] = $falt;
        }
    }

    echo '<div class="resumen-table-wrap">';
    echo '<table class="resumen-desktop' . ($groupSup ? ' is-grouped' : '') . '">';
    echo '<tr><th colspan="' . ($groupSup ? '6' : '8') . '" class="resumen-top-cell">';
    $pctGlobalRaw = $total_cuota > 0 ? (($total_monto / $total_cuota) * 100) : 0;
    $pctGlobal = ($pctGlobalRaw < 100) ? floor($pctGlobalRaw) : round($pctGlobalRaw);
    echo '<div class="resumen-top-row">';
    echo '<div class="resumen-kpis">';
    echo '<span class="kpi-chip kpi-pedidos">Pedidos: <b>' . $total_pedidos . '</b></span>';
    echo '<span class="kpi-chip kpi-monto">Venta S/ <b>' . number_format($total_monto, 2, '.', ',') . '</b></span>';
    echo '<span class="kpi-chip kpi-cuota">Cuota S/ <b>' . number_format($total_cuota, 2, '.', ',') . '</b></span>';
    echo '<span class="kpi-chip kpi-faltante">Faltante S/ <b>' . number_format($total_faltante_view, 2, '.', ',') . '</b></span>';
    echo '</div>';
    echo '<div class="resumen-avance-block">';
    echo '<span class="kpi-progress-wrap">' . renderResumenProgress((float)$pctGlobal, 'progress-global') . '</span>';
    echo '</div>';
    echo '</div>';
    echo '</th></tr>';
    if ($groupSup) {
        echo '<tr><th>Supervisor</th><th>Ctd_Pedidos</th><th>Total_IGV</th><th>Cuota (S/)</th><th>Faltante (S/)</th><th>Avance</th></tr>';
        foreach ($groups as $g) {
            $ventaVal = (float)$g['total_igv'];
            $cuotaVal = (float)$g['cuota'];
            $faltanteVal = (float)$g['faltante'];
            $pctRaw = $cuotaVal > 0 ? (($ventaVal / $cuotaVal) * 100) : 0;
            $pct = ($pctRaw < 100) ? floor($pctRaw) : round($pctRaw);
            echo '<tr>';
            echo '<td>' . htmlspecialchars($g['Supervisor']) . '</td>';
            echo '<td>' . htmlspecialchars($g['ctd_pedidos']) . '</td>';
            echo '<td>' . number_format($ventaVal, 2, '.', ',') . '</td>';
            echo '<td>' . number_format($cuotaVal, 2, '.', ',') . '</td>';
            echo '<td>' . number_format($faltanteVal, 2, '.', ',') . '</td>';
            echo '<td class="avance-cell">' . renderResumenProgress((float)$pct) . '</td>';
            echo '</tr>';
        }
        echo '</table></div>';
    } else {
        echo '<tr><th>Cod_Vendedor</th><th>Nom_Vendedor</th><th>Supervisor</th><th>Ctd_Pedidos</th><th>Total_IGV</th><th>Cuota (S/)</th><th>Faltante (S/)</th><th>Avance</th></tr>';
        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($row['Cod_Vendedor']) . '</td>';
            echo '<td>' . htmlspecialchars($row['Nom_Vendedor']) . '</td>';
            echo '<td>' . htmlspecialchars($row['Supervisor']) . '</td>';
            $cuotaVal = isset($row['CuotaVal']) ? (float)$row['CuotaVal'] : 0.0;
            $faltanteVal = max(0, $cuotaVal - (float)$row['total_igv']);
            echo '<td>' . htmlspecialchars($row['ctd_pedidos']) . '</td>';
            echo '<td>' . number_format($row['total_igv'], 2, '.', ',') . '</td>';
            echo '<td>' . number_format($cuotaVal, 2, '.', ',') . '</td>';
            echo '<td>' . number_format($faltanteVal, 2, '.', ',') . '</td>';
            $ventaVal = (float)$row['total_igv'];
            $pctRaw = $cuotaVal > 0 ? (($ventaVal / $cuotaVal) * 100) : 0;
            $pct = ($pctRaw < 100) ? floor($pctRaw) : round($pctRaw);
            echo '<td class="avance-cell">' . renderResumenProgress((float)$pct) . '</td>';
            echo '</tr>';
        }
        echo '</table></div>';
    }
    
        // Versión móvil tipo lista con resumen global
        echo '<div class="resumen-mobile">';
        echo '<div class="rm-global">';
        echo   '<div class="rg-metrics">'
                        . '<span><strong>Pedidos:</strong> ' . $total_pedidos . '</span>'
                        . '<span><strong>Monto:</strong> S/ ' . number_format($total_monto, 2, '.', ',') . '</span>'
                        . '<span><strong>Cuota:</strong> S/ ' . number_format($total_cuota, 2, '.', ',') . '</span>'
                        . '<span><strong>Faltante:</strong> S/ ' . number_format($total_faltante_view, 2, '.', ',') . '</span>'
                        . '<span><strong>Avance:</strong> ' . $pctGlobal . '%</span>'
                    . '</div>';
        echo   '<div class="rg-bar">' . renderResumenProgress((float)$pctGlobal, 'rm-progress') . '</div>';
        echo '</div>';
        echo '<h3 class="rm-title">Resumen de Avance' . ($supervisor ? ' — ' . htmlspecialchars($supervisor) : '') . '</h3>';
    echo '<div class="rm-list">';
    if ($groupSup) {
        // Lista móvil agrupada por supervisor
        // Reutilizar grupos construidos arriba si existe, sino construir aquí
        $groups2 = [];
        foreach ($groups as $supName => $g) {
            $groups2[$supName] = [
                'ctd_pedidos' => (int)$g['ctd_pedidos'],
                'total_igv' => (float)$g['total_igv'],
                'cuota' => (float)$g['cuota']
            ];
        }
        foreach ($groups2 as $supName => $g) {
            $ventaVal = (float)$g['total_igv'];
            $cuotaVal = (float)$g['cuota'];
            $pctRaw = $cuotaVal > 0 ? (($ventaVal / $cuotaVal) * 100) : 0;
            $pct = ($pctRaw < 100) ? floor($pctRaw) : round($pctRaw);
            $label = htmlspecialchars($supName);
            $montoFmt = 'S/ ' . number_format($ventaVal, 2, '.', ',');
            $cuotaFmt = $cuotaVal > 0 ? ('S/ ' . number_format($cuotaVal, 2, '.', ',')) : '—';
            $faltFmt = 'S/ ' . number_format(max(0, $cuotaVal - $ventaVal), 2, '.', ',');
            echo '<div class="rm-item">';
            echo   '<div class="rm-label">' . $label . '<div class="rm-sub">' . $montoFmt . ' / ' . $cuotaFmt . ' / Faltante: ' . $faltFmt . '</div></div>';
            echo   '<div class="rm-progress-wrap">' . renderResumenProgress((float)$pct, 'rm-progress') . '</div>';
            echo '</div>';
        }
    } else {
    foreach ($rows as $row) {
        $vdRaw = trim((string)$row['Cod_Vendedor']);
        $vdNoZeros = ltrim($vdRaw, '0');
        if ($vdNoZeros === '') { $vdNoZeros = '0'; }
        $vdPadded3 = str_pad($vdNoZeros, 3, '0', STR_PAD_LEFT);

        $ventaVal = (float)$row['total_igv'];
        $cuotaVal = isset($row['CuotaVal']) ? (float)$row['CuotaVal'] : 0.0;
        $pctRaw = $cuotaVal > 0 ? (($ventaVal / $cuotaVal) * 100) : 0;
        $pct = ($pctRaw < 100) ? floor($pctRaw) : round($pctRaw);
        $label = htmlspecialchars($vdPadded3 . ' - ' . $row['Nom_Vendedor']);
        $montoFmt = 'S/ ' . number_format($ventaVal, 2, '.', ',');
        $cuotaFmt = $cuotaVal > 0 ? ('S/ ' . number_format($cuotaVal, 2, '.', ',')) : '—';
        $faltFmt = 'S/ ' . number_format(max(0, $cuotaVal - $ventaVal), 2, '.', ',');
    echo '<div class="rm-item">';
    echo   '<div class="rm-label">' . $label . '<div class="rm-sub">' . $montoFmt . ' / ' . $cuotaFmt . ' / Faltante: ' . $faltFmt . '</div></div>';
    echo   '<div class="rm-progress-wrap">' . renderResumenProgress((float)$pct, 'rm-progress') . '</div>';
    echo '</div>';
    }
    }
    echo '</div>'; // .rm-list
    echo '</div>'; // .resumen-mobile
} else {
    echo '<p>No hay pedidos para hoy.</p>';
}
$stmt->close();
$mysqli->close();
?>
