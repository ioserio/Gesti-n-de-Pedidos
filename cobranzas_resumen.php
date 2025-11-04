<?php
require_once __DIR__ . '/require_login.php';
// Resumen de cobranzas por vendedor (responsable)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'conexion.php';

$cod_vendedor = isset($_GET['cod_vendedor']) ? trim($_GET['cod_vendedor']) : '';

// Si hay vendedor: mostrar detalle de documentos con columnas específicas
if ($cod_vendedor !== '') {
   $sql = "SELECT 
            documentopagofechaemision AS fecha,
            documentopagofechavencimiento AS fechavenc,
                documentopagoresponsablecodigo AS vendedor,
                documentopagozonacodigo AS ruta,
                documentopagopersonacodigo AS codigo_cliente,
                documentopagopersonanombre AS nombre_cliente,
                documentopagotipoabreviacion AS tipodoc,
                documentopagonumero AS numerodoc,
                documentopagomontosoles AS total,
                documentopagosaldosoles AS saldo
            FROM cuentas_por_cobrar_pagar
            WHERE documentopagoresponsablecodigo = ?
            ORDER BY documentopagofechaemision DESC, numerodoc ASC";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) { die('<p>Error preparando consulta: ' . htmlspecialchars($mysqli->error) . '</p>'); }
    $stmt->bind_param('s', $cod_vendedor);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) { $rows[] = $r; }
    $stmt->close();
    $mysqli->close();

    if (empty($rows)) { echo '<p>No hay documentos para el vendedor especificado.</p>'; exit; }

    // Totales
    $sum_total = 0.0; $sum_saldo = 0.0; $cnt = 0;
    foreach ($rows as $r) { $sum_total += (float)$r['total']; $sum_saldo += (float)$r['saldo']; $cnt++; }

   echo '<div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; margin-bottom:8px;">';
   echo '<div><b>Vendedor:</b> ' . htmlspecialchars($cod_vendedor) . ' &nbsp; <b>Documentos:</b> ' . number_format($cnt,0,'.',',') . '</div>';
   echo '<div><b>Total S/:</b> ' . number_format($sum_total,2,'.',',') . ' &nbsp; <b>Saldo S/:</b> ' . number_format($sum_saldo,2,'.',',') . '</div>';
   echo '<div style="margin-left:auto"><button type="button" class="btn-print" onclick="window.print()">Imprimir</button></div>';
   echo '</div>';

   echo '<table class="tabla-cobranzas">';
   echo '<thead><tr>'
       . '<th style="min-width:110px">Fecha</th>'
       . '<th style="min-width:80px">Vendedor</th>'
       . '<th style="min-width:80px">Ruta</th>'
       . '<th style="min-width:110px">Código Cliente</th>'
       . '<th>Nombre Cliente</th>'
      . '<th style="min-width:90px">DiasVcto</th>'
      . '<th style="min-width:80px">TipoDoc</th>'
       . '<th style="min-width:110px">NumeroDoc</th>'
      . '<th style="min-width:120px">Condición</th>'
       . '<th style="text-align:right; min-width:110px">Total</th>'
       . '<th style="text-align:right; min-width:110px">Saldo</th>'
       . '</tr></thead>';
    echo '<tbody>';
   $hoy = new DateTime('today');
   foreach ($rows as $r) {
      $fecha = $r['fecha'];
      $fv = $r['fechavenc'] ?? null;
        // Mostrar fecha como YYYY-MM-DD si viene con hora
        if ($fecha && strlen($fecha) > 10) { $fecha = substr($fecha,0,10); }
      if ($fv && strlen($fv) > 10) { $fv = substr($fv,0,10); }

      // Calcular condición (Contado / Crédito X días)
      $cond = '-';
      if ($fecha && $fv) {
         try {
            $d1 = new DateTime($fecha);
            $d2 = new DateTime($fv);
            if ($d2 <= $d1) {
               $cond = 'Contado';
            } else {
               $diff = $d1->diff($d2);
               $cond = 'Crédito ' . $diff->days . ' días';
            }
         } catch (Exception $e) {
            $cond = '-';
         }
      }

      // Calcular DiasVcto = días vencidos contando desde el día siguiente al vencimiento
      $diasVcto = '-';
      $vctoClass = 'vcto-unk';
      if ($fv) {
         try {
            $dv = new DateTime($fv);
            if ($hoy > $dv) {
               $diasVcto = $dv->diff($hoy)->days; // si hoy es día siguiente, 1
               // Colorear: 1-7 amarillo, 8-15 naranja, 16+ rojo
               if ($diasVcto >= 16) $vctoClass = 'vcto-red';
               else if ($diasVcto >= 8) $vctoClass = 'vcto-orange';
               else if ($diasVcto >= 1) $vctoClass = 'vcto-yellow';
            } else {
               $diasVcto = 0; // aún no vencido o vence hoy
               $vctoClass = 'vcto-ok';
            }
         } catch (Exception $e) {
            $diasVcto = '-';
            $vctoClass = 'vcto-unk';
         }
      }
        echo '<tr>'
           . '<td>' . htmlspecialchars((string)$fecha) . '</td>'
           . '<td>' . htmlspecialchars((string)$r['vendedor']) . '</td>'
           . '<td>' . htmlspecialchars((string)$r['ruta']) . '</td>'
           . '<td>' . htmlspecialchars((string)$r['codigo_cliente']) . '</td>'
           . '<td>' . htmlspecialchars((string)$r['nombre_cliente']) . '</td>'
           . '<td><span class="vcto"><span class="vcto-dot ' . $vctoClass . '"></span><span>' . htmlspecialchars((string)$diasVcto) . '</span></span></td>'
           . '<td>' . htmlspecialchars((string)$r['tipodoc']) . '</td>'
           . '<td>' . htmlspecialchars((string)$r['numerodoc']) . '</td>'
         . '<td>' . htmlspecialchars((string)$cond) . '</td>'
           . '<td style="text-align:right;">' . number_format((float)$r['total'],2,'.',',') . '</td>'
           . '<td style="text-align:right;">' . number_format((float)$r['saldo'],2,'.',',') . '</td>'
           . '</tr>';
    }
    echo '</tbody>';
    echo '</table>';
    exit;
}

// Sin vendedor: mostrar resumen por vendedor (conteo)
$sql = "SELECT documentopagoresponsablecodigo AS cod_vendedor,
               COALESCE(documentopagoresponsablenombre,'') AS nombre_vendedor,
               COUNT(*) AS total_documentos
        FROM cuentas_por_cobrar_pagar
        GROUP BY documentopagoresponsablecodigo, documentopagoresponsablenombre
        ORDER BY total_documentos DESC, documentopagoresponsablecodigo ASC";

$stmt = $mysqli->prepare($sql);
if (!$stmt) { die('<p>Error preparando consulta: ' . htmlspecialchars($mysqli->error) . '</p>'); }
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($r = $res->fetch_assoc()) { $rows[] = $r; }
$stmt->close();
$mysqli->close();

if (empty($rows)) { echo '<p>No hay documentos que coincidan con el filtro.</p>'; exit; }

$total_global = 0;
foreach ($rows as $r) { $total_global += (int)$r['total_documentos']; }

echo '<div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; margin-bottom:8px;">';
echo '<div><b>Total documentos:</b> ' . number_format($total_global, 0, '.', ',') . '</div>';
echo '<div style="margin-left:auto"><button type="button" class="btn-print" onclick="window.print()">Imprimir</button></div>';
echo '</div>';

echo '<table class="tabla-cobranzas">';
echo '<thead><tr>'
   . '<th style="min-width:90px">Cod Vendedor</th>'
   . '<th>Nombre</th>'
   . '<th style="text-align:right; min-width:120px">Total Documentos</th>'
   . '</tr></thead>';
echo '<tbody>';
foreach ($rows as $r) {
    echo '<tr>'
       . '<td>' . htmlspecialchars($r['cod_vendedor']) . '</td>'
       . '<td>' . htmlspecialchars($r['nombre_vendedor']) . '</td>'
       . '<td style="text-align:right;">' . number_format((int)$r['total_documentos'], 0, '.', ',') . '</td>'
       . '</tr>';
}
echo '</tbody>';
echo '</table>';

?>
