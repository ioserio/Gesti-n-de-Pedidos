<?php
if (!isset($_GET['cod_vendedor']) || empty($_GET['cod_vendedor'])) {
    echo '<p>Debe ingresar un código de vendedor.</p>';
    exit();
}

$cod_vendedor = $_GET['cod_vendedor'];


require_once 'conexion.php';



$fecha_hoy = date('Y-m-d');
$sql = "SELECT Fecha, Cod_Vendedor, Nom_Vendedor, Codigo, Nombre, Total_IGV, Zona FROM pedidos_x_dia WHERE Cod_Vendedor = ? AND Fecha = ? ORDER BY Nombre ASC";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param('ss', $cod_vendedor, $fecha_hoy);
$stmt->execute();
$result = $stmt->get_result();

$cantidad = $result->num_rows;
$total_soles = 0;
$rows = [];
while ($row = $result->fetch_assoc()) {
    // Limpiar el valor de Total_IGV: quitar separador de miles (coma) y dejar el punto como decimal
    $total_igv = str_replace(',', '', $row['Total_IGV']);
    $row['Total_IGV'] = $total_igv;
    $rows[] = $row;
    $total_soles += floatval($total_igv);
}

// Obtener cuota del vendedor para hoy (día de semana)
$dow = intval(date('N', strtotime($fecha_hoy))); // 1..7
$codRaw = trim((string)$cod_vendedor);
$codNoZeros = ltrim($codRaw, '0'); if ($codNoZeros==='') $codNoZeros='0';
$codPadded3 = str_pad($codNoZeros, 3, '0', STR_PAD_LEFT);
$cuota_vendedor = 0.0;
$q = $mysqli->prepare("SELECT Cuota FROM cuotas_vendedor WHERE Dia_Semana=? AND Cod_Vendedor=? LIMIT 1");
if ($q) { $q->bind_param('is', $dow, $codPadded3); $q->execute(); $qr=$q->get_result(); if ($qr && $qr->num_rows>0){ $cuota_vendedor = (float)($qr->fetch_assoc()['Cuota']); } $q->close(); }

if ($cantidad > 0) {
    echo '<table>';
    echo '<tr>';
    echo '<th colspan="7" style="text-align:left; background:#e6f2ff; font-size:17px;">';
    echo 'Pedidos: <b>' . $cantidad . '</b> &nbsp;|&nbsp; Total S/ <b>' . number_format($total_soles, 2, '.', ',') . '</b>';
    if ($cuota_vendedor > 0) {
        $pctRaw = ($total_soles / $cuota_vendedor) * 100;
        $pct = ($pctRaw < 100) ? floor($pctRaw) : round($pctRaw);
        $pctCap = max(0, min(100, $pct));
        $barClass = 'bar-red';
        if ($pct >= 100) { $barClass = 'bar-green'; }
        elseif ($pct >= 80) { $barClass = 'bar-yellow'; }
        elseif ($pct >= 50) { $barClass = 'bar-orange'; }
        echo ' &nbsp;|&nbsp; Cuota S/ <b>' . number_format($cuota_vendedor, 2, '.', ',') . '</b> &nbsp;|&nbsp; Avance <b>' . $pct . '%</b>';
        echo ' <span class="progress progress-global"><span class="bar ' . $barClass . '" style="width:' . $pctCap . '%"></span></span>';
    }
    echo ' &nbsp; <button onclick="window.print()" style="float:right; background:#007bff; color:#fff; border:none; padding:6px 16px; border-radius:4px; cursor:pointer; font-size:15px;">Imprimir PDF</button>';
    echo '</th>';
    echo '</tr>';
    echo '<tr><th>Fecha</th><th>Cod_Vendedor</th><th>Nom_Vendedor</th><th>Codigo</th><th>Nombre</th><th>Total_IGV</th><th>Zona</th></tr>';
    foreach ($rows as $row) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($row['Fecha']) . '</td>';
        echo '<td>' . htmlspecialchars($row['Cod_Vendedor']) . '</td>';
        echo '<td>' . htmlspecialchars($row['Nom_Vendedor']) . '</td>';
        echo '<td>' . htmlspecialchars($row['Codigo']) . '</td>';
        echo '<td>' . htmlspecialchars($row['Nombre']) . '</td>';
        echo '<td>' . htmlspecialchars($row['Total_IGV']) . '</td>';
        echo '<td>' . htmlspecialchars($row['Zona']) . '</td>';
        echo '</tr>';
    }
    echo '</table>';
} else {
    echo '<p>No se encontraron pedidos para este vendedor.</p>';
}

$stmt->close();
$mysqli->close();
?>
