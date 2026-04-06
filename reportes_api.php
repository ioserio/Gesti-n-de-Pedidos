<?php
require_once __DIR__ . '/require_login.php';
require_once __DIR__ . '/conexion.php';

header('Content-Type: text/html; charset=utf-8');

$action = $_GET['action'] ?? 'resumen_cobranzas';

if ($action === 'resumen_cobranzas') {
    $sql = "SELECT 
                COALESCE(s.nombre, 'SIN SUPERVISOR') as supervisor,
                COUNT(c.id) as total_documentos,
                SUM(c.documentopagosaldosoles) as saldo_pendiente
            FROM cuentas_por_cobrar_pagar c
            LEFT JOIN vendedor_supervisor vs ON vs.codigo_vendedor = c.documentopagoresponsablecodigo
            LEFT JOIN sup_ctacte s ON s.numero = vs.numero_supervisor
            GROUP BY s.nombre
            ORDER BY saldo_pendiente DESC";

    $res = $mysqli->query($sql);
    
    if (!$res) {
        echo "<p>Error al generar reporte: " . htmlspecialchars($mysqli->error) . "</p>";
        exit;
    }

    echo "<h3>Resumen de Saldos Pendientes por Supervisor</h3>";
    echo "<table class='tabla-reporte'>";
    echo "<thead>
            <tr>
                <th>Supervisor</th>
                <th style='text-align:center;'>Documentos</th>
                <th style='text-align:right;'>Saldo Total Pendiente (S/)</th>
            </tr>
          </thead>";
    echo "<tbody>";
    
    $gran_total = 0;
    while ($row = $res->fetch_assoc()) {
        $gran_total += (float)$row['saldo_pendiente'];
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['supervisor']) . "</td>";
        echo "<td style='text-align:center;'>" . number_format($row['total_documentos'], 0) . "</td>";
        echo "<td style='text-align:right; font-weight:bold;'>S/ " . number_format($row['saldo_pendiente'], 2) . "</td>";
        echo "</tr>";
    }
    
    echo "</tbody>";
    echo "<tfoot>
            <tr style='background:#f1f5f9; font-weight:800;'>
                <td colspan='2'>TOTAL GENERAL</td>
                <td style='text-align:right;'>S/ " . number_format($gran_total, 2) . "</td>
            </tr>
          </tfoot>";
    echo "</table>";
}
