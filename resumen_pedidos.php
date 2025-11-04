<?php
require_once __DIR__ . '/require_login.php';
require_once 'conexion.php';

$fecha = isset($_GET['fecha']) ? $_GET['fecha'] : date('Y-m-d');
$supervisor = isset($_GET['supervisor']) ? $_GET['supervisor'] : '';

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

$sql = "SELECT Cod_Vendedor, Nom_Vendedor, COUNT(*) AS ctd_pedidos, SUM(CAST(REPLACE(Total_IGV, ',', '') AS DECIMAL(12,2))) AS total_igv FROM pedidos_x_dia WHERE Fecha = ? GROUP BY Cod_Vendedor, Nom_Vendedor ORDER BY Cod_Vendedor ASC";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param('s', $fecha);
$stmt->execute();
$result = $stmt->get_result();

// Obtener cuotas por día de semana
$dow = intval(date('N', strtotime($fecha))); // 1=Lunes ... 7=Domingo
$cuotas = [];
$q = $mysqli->prepare("SELECT Cod_Vendedor, Cuota FROM cuotas_vendedor WHERE Dia_Semana=?");
if ($q) { $q->bind_param('i', $dow); $q->execute(); $r = $q->get_result();
    while ($rowq = $r->fetch_assoc()) { $cuotas[$rowq['Cod_Vendedor']] = (float)$rowq['Cuota']; }
    $q->close();
}

if ($result->num_rows > 0) {
    $total_pedidos = 0;
    $total_monto = 0;
    $total_cuota = 0.0;
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        // Normalizar código de vendedor y buscar supervisor por varias formas
        $vdRaw = trim((string)$row['Cod_Vendedor']);
        $vdNoZeros = ltrim($vdRaw, '0');
        if ($vdNoZeros === '') { $vdNoZeros = '0'; }
        $vdPadded3 = str_pad($vdNoZeros, 3, '0', STR_PAD_LEFT);

        $sup = '';
        if (isset($vd_supervisor[$vdRaw])) {
            $sup = $vd_supervisor[$vdRaw];
        } elseif (isset($vd_supervisor[$vdNoZeros])) {
            $sup = $vd_supervisor[$vdNoZeros];
        } elseif (isset($vd_supervisor[$vdPadded3])) {
            $sup = $vd_supervisor[$vdPadded3];
        }
        $row['Supervisor'] = $sup;
        // Filtrar por supervisor si se seleccionó
        if ($supervisor && $row['Supervisor'] !== $supervisor) continue;
        // Calcular cuota del vendedor para el día
        $cuotaVal = 0.0;
        if (isset($cuotas[$vdRaw])) $cuotaVal = (float)$cuotas[$vdRaw];
        elseif (isset($cuotas[$vdNoZeros])) $cuotaVal = (float)$cuotas[$vdNoZeros];
        elseif (isset($cuotas[$vdPadded3])) $cuotaVal = (float)$cuotas[$vdPadded3];
        $row['CuotaVal'] = $cuotaVal;

        $total_pedidos += intval($row['ctd_pedidos']);
        $total_monto += floatval($row['total_igv']);
        $total_cuota += $cuotaVal;
        $rows[] = $row;
    }
    echo '<table>';
    echo '<tr><th colspan="7" style="text-align:left; background:#e6f2ff; font-size:17px;">';
    echo 'Pedidos totales: <b>' . $total_pedidos . '</b> &nbsp;|&nbsp; Monto total S/ <b>' . number_format($total_monto, 2, '.', ',') . '</b>';
    $pctGlobalRaw = $total_cuota > 0 ? (($total_monto / $total_cuota) * 100) : 0;
    $pctGlobal = ($pctGlobalRaw < 100) ? floor($pctGlobalRaw) : round($pctGlobalRaw);
    $pctGlobalCap = max(0, min(100, $pctGlobal));
    $gBarClass = 'bar-red';
    if ($pctGlobal >= 100) { $gBarClass = 'bar-green'; }
    elseif ($pctGlobal >= 80) { $gBarClass = 'bar-yellow'; }
    elseif ($pctGlobal >= 50) { $gBarClass = 'bar-orange'; }
    echo ' &nbsp;|&nbsp; Cuota total S/ <b>' . number_format($total_cuota, 2, '.', ',') . '</b> &nbsp;|&nbsp; Avance <b>' . $pctGlobal . '%</b>';
    echo ' <span class="progress progress-global"><span class="bar ' . $gBarClass . '" style="width:' . $pctGlobalCap . '%"></span></span>';
    echo ' &nbsp; <button onclick="window.print()" style="float:right; background:#007bff; color:#fff; border:none; padding:6px 16px; border-radius:4px; cursor:pointer; font-size:15px;">Imprimir PDF</button>';
    echo '</th></tr>';
    echo '<tr><th>Cod_Vendedor</th><th>Nom_Vendedor</th><th>Supervisor</th><th>Ctd_Pedidos</th><th>Total_IGV</th><th>Cuota (S/)</th><th>Avance</th></tr>';
    foreach ($rows as $row) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($row['Cod_Vendedor']) . '</td>';
        echo '<td>' . htmlspecialchars($row['Nom_Vendedor']) . '</td>';
        echo '<td>' . htmlspecialchars($row['Supervisor']) . '</td>';
    // cuota ya calculada
    $cuotaVal = isset($row['CuotaVal']) ? (float)$row['CuotaVal'] : 0.0;
        echo '<td>' . htmlspecialchars($row['ctd_pedidos']) . '</td>';
        echo '<td>' . number_format($row['total_igv'], 2, '.', ',') . '</td>';
        echo '<td>' . number_format($cuotaVal, 2, '.', ',') . '</td>';
        // Barra de avance
    $ventaVal = (float)$row['total_igv'];
    $pctRaw = $cuotaVal > 0 ? (($ventaVal / $cuotaVal) * 100) : 0;
    $pct = ($pctRaw < 100) ? floor($pctRaw) : round($pctRaw);
        $pctCap = max(0, min(100, $pct));
        $barClass = 'bar-red';
        if ($pct >= 100) { $barClass = 'bar-green'; }
        elseif ($pct >= 80) { $barClass = 'bar-yellow'; }
        elseif ($pct >= 50) { $barClass = 'bar-orange'; }
    echo '<td class="avance-cell"><div class="progress"><div class="bar ' . $barClass . '" style="width:' . $pctCap . '%"></div></div><small>' . $pct . '%</small></td>';
        echo '</tr>';
    }
    echo '</table>';
} else {
    echo '<p>No hay pedidos para hoy.</p>';
}
$stmt->close();
$mysqli->close();
?>
