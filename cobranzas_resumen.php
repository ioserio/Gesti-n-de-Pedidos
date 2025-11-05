<?php
require_once __DIR__ . '/require_login.php';
// Resumen de cobranzas por vendedor (responsable)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'conexion.php';

// Helpers: detectar tabla de rutas y obtener rutas del VD para el día de consulta (siguiente día)
function table_exists(mysqli $mysqli, string $table): bool {
   $tbl = $mysqli->real_escape_string($table);
   $sql = "SHOW TABLES LIKE '$tbl'";
   if ($res = $mysqli->query($sql)) {
      $exists = ($res->num_rows > 0);
      $res->close();
      return $exists;
   }
   return false;
}

function nombre_dia_para_consulta(): array {
   // Regla: usar el día SIGUIENTE; si hoy es sábado (6), usar LUNES (1).
   // Nota: si es domingo (7), siguiente es lunes (1) por rotación.
   $hoy = (int)date('N'); // 1=lunes .. 7=domingo
   $nombres = [
      1 => ['lunes','Lunes'],
      2 => ['martes','Martes'],
      3 => ['miercoles','Miercoles'], // sin acento por compatibilidad
      4 => ['jueves','Jueves'],
      5 => ['viernes','Viernes'],
      6 => ['sabado','Sabado'],
      7 => ['domingo','Domingo'],
   ];
   if ($hoy === 6) { // sábado -> lunes
      $next = 1;
   } else {
      $next = ($hoy % 7) + 1; // lunes->martes ... domingo->lunes
   }
   return [$next, $nombres[$next][0], $nombres[$next][1]];
}

// Día presente (hoy), para mostrar en encabezados
function nombre_dia_hoy(): array {
   $hoy = (int)date('N'); // 1=lunes .. 7=domingo
   $nombres = [
      1 => ['lunes','Lunes'],
      2 => ['martes','Martes'],
      3 => ['miercoles','Miercoles'],
      4 => ['jueves','Jueves'],
      5 => ['viernes','Viernes'],
      6 => ['sabado','Sabado'],
      7 => ['domingo','Domingo'],
   ];
   return [$hoy, $nombres[$hoy][0], $nombres[$hoy][1]];
}

function get_vendor_routes_today(mysqli $mysqli, string $vd3): array {
   // Para consistencia con el requerimiento, "hoy" refiere al día de consulta definido arriba (siguiente día)
   [$dow, $nombreMin, $nombreTitulo] = nombre_dia_para_consulta();
   $rutas = [];

   // Caso 1: nuestra tabla rutas_vendedor (Cod_Vendedor, Dia_Semana, Zona)
   if (table_exists($mysqli, 'rutas_vendedor')) {
      $sql = "SELECT Zona FROM rutas_vendedor WHERE Cod_Vendedor = ? AND Dia_Semana = ?";
      if ($stmt = $mysqli->prepare($sql)) {
         $stmt->bind_param('si', $vd3, $dow);
         $stmt->execute();
         $res = $stmt->get_result();
         while ($row = $res->fetch_assoc()) {
            if (!empty($row['Zona'])) { $rutas[] = trim((string)$row['Zona']); }
         }
         $stmt->close();
      }
   }

   // Caso 2: tabla del cliente con columnas (VD, NombreDia, Zona)
   if (empty($rutas) && table_exists($mysqli, 'rutas')) {
      $sql = "SELECT Zona FROM rutas WHERE VD = ? AND (LOWER(NombreDia) = ? OR NombreDia = ?)";
      if ($stmt = $mysqli->prepare($sql)) {
         $lower = $nombreMin; // e.g., 'martes'
         $titulo = $nombreTitulo; // e.g., 'Martes'
         $stmt->bind_param('sss', $vd3, $lower, $titulo);
         $stmt->execute();
         $res = $stmt->get_result();
         while ($row = $res->fetch_assoc()) {
            if (!empty($row['Zona'])) { $rutas[] = trim((string)$row['Zona']); }
         }
         $stmt->close();
      }
   }

   // Unicas y ordenadas
   $rutas = array_values(array_unique(array_filter($rutas, fn($z) => $z !== '')));
   return $rutas;
}

$cod_vendedor = isset($_GET['cod_vendedor']) ? trim($_GET['cod_vendedor']) : '';
$supervisorParam = isset($_GET['supervisor']) ? trim((string)$_GET['supervisor']) : '';
// Normalizar VD a 3 dígitos para consultas de rutas (e.g., '1' -> '001')
$vdNorm = str_pad(preg_replace('/\D/','', $cod_vendedor), 3, '0', STR_PAD_LEFT);

// Si hay vendedor: mostrar detalle de documentos con columnas específicas
if ($cod_vendedor !== '') {
   // Obtener rutas del VD para el día de consulta (siguiente)
   $rutasHoy = get_vendor_routes_today($mysqli, $vdNorm);

   // Construir condición por rutas (si no hay rutas, devolverá vacío)
   $rutaCond = '';
   if (!empty($rutasHoy)) {
      $inVals = [];
      foreach ($rutasHoy as $z) {
         // Sanitizar cada zona/ruta a valores seguros (alfanumérico, guion y guion bajo)
         $z = trim((string)$z);
         if ($z === '') continue;
         if (!preg_match('/^[0-9A-Za-z_-]+$/', $z)) continue;
         $inVals[] = "'" . $mysqli->real_escape_string($z) . "'";
      }
      if (!empty($inVals)) {
         $rutaCond = ' AND documentopagozonacodigo IN (' . implode(',', $inVals) . ')';
      } else {
         // Si tras sanitizar no quedó ninguna ruta, forzar sin resultados
         $rutaCond = ' AND 1=0';
      }
   } else {
      // Sin rutas asignadas para hoy => no hay documentos que mostrar
      $rutaCond = ' AND 1=0';
   }

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
         WHERE documentopagoresponsablecodigo = ?" . $rutaCond . "
         ORDER BY documentopagofechaemision DESC, numerodoc ASC";
   $stmt = $mysqli->prepare($sql);
    if (!$stmt) { die('<p>Error preparando consulta: ' . htmlspecialchars($mysqli->error) . '</p>'); }
   $stmt->bind_param('s', $vdNorm);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) { $rows[] = $r; }
   $stmt->close();

   if (empty($rows)) { echo '<p>No hay documentos para el vendedor y rutas asignadas para el día siguiente.</p>'; exit; }

    // Totales
    $sum_total = 0.0; $sum_saldo = 0.0; $cnt = 0;
    foreach ($rows as $r) { $sum_total += (float)$r['total']; $sum_saldo += (float)$r['saldo']; $cnt++; }

   // Encabezado de supervisor centrado si viene por parámetro o puede inferirse por mapeo
   $supTitulo = '';
   if ($supervisorParam !== '') {
      $supTitulo = $supervisorParam;
   }
   // No es confiable reabrir la conexión aquí, así que haremos una consulta simple usando la conexión inicial antes de close()

   // Preparar Título centrado: DIA - SUPERVISOR (MAYÚSCULAS)
   // Mostrar día presente en el encabezado
   [$dow, $diaMin, $diaTitulo] = nombre_dia_hoy();
   $diaUp = strtoupper($diaTitulo);
   $supShown = '';
   if ($supervisorParam !== '') {
      $supShown = $supervisorParam;
   } else {
      // Intentar obtener supervisor por mapeo
      $sqlSup = "SELECT s.nombre FROM vendedor_supervisor vs JOIN sup_ctacte s ON s.numero = vs.numero_supervisor WHERE vs.codigo_vendedor = ? LIMIT 1";
      if ($stmt2 = $mysqli->prepare($sqlSup)) {
         $stmt2->bind_param('s', $vdNorm);
         if ($stmt2->execute()) {
            $rs2 = $stmt2->get_result();
            if ($r2 = $rs2->fetch_assoc()) { $supShown = (string)$r2['nombre']; }
         }
         $stmt2->close();
      }
   }
   // Si nos llegó "1 - ELSA" o similar, quedarnos con el nombre (luego de " - ")
   if ($supShown && strpos($supShown, ' - ') !== false) {
      $parts = explode(' - ', $supShown, 2);
      $supShown = trim($parts[1]);
   }
   $supUp = $supShown ? strtoupper($supShown) : '';

   // Barra superior con izquierda (vd/docs), centro (día - supervisor), derecha (totales + imprimir)
   echo '<div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:8px;">';
   echo '<div style="min-width:280px;"><b>Vendedor:</b> ' . htmlspecialchars($cod_vendedor) . ' &nbsp; <b>Documentos:</b> ' . number_format($cnt,0,'.',',') . '</div>';
   echo '<div style="flex:1; text-align:center; font-weight:800; font-size:20px;">' . htmlspecialchars(trim($diaUp . ($supUp?(' - ' . $supUp):'')), ENT_QUOTES, 'UTF-8') . '</div>';
   echo '<div style="margin-left:auto; display:flex; align-items:center; gap:12px;"><div><b>Total S/:</b> ' . number_format($sum_total,2,'.',',') . ' &nbsp; <b>Saldo S/:</b> ' . number_format($sum_saldo,2,'.',',') . '</div><button type="button" class="btn-print" onclick="window.print()">Imprimir</button></div>';
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

      // Calcular DiasVcto = días de morosidad desde la FECHA DE EMISIÓN (inclusive)
      $diasVcto = '-';
      $vctoClass = 'vcto-unk';
      if ($fecha) {
         try {
            $de = new DateTime($fecha);
            if ($hoy >= $de) {
               // Inclusivo: el día de emisión cuenta como día 1
               $diasVcto = $de->diff($hoy)->days + 1;
               // Colorear: 1-7 amarillo, 8-15 naranja, 16+ rojo
               if ($diasVcto >= 16) $vctoClass = 'vcto-red';
               else if ($diasVcto >= 8) $vctoClass = 'vcto-orange';
               else if ($diasVcto >= 1) $vctoClass = 'vcto-yellow';
            } else {
               // Emisión en el futuro (caso atípico)
               $diasVcto = 0;
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

if (empty($rows)) { echo '<p>No hay documentos que coincidan con el filtro.</p>'; exit; }

$total_global = 0;
foreach ($rows as $r) { $total_global += (int)$r['total_documentos']; }

// Barra superior para resumen: centro DIA - SUPERVISOR (si viene), izquierda totales, derecha imprimir
// Mostrar día presente en el encabezado (resumen)
[$dow, $diaMin, $diaTitulo] = nombre_dia_hoy();
$diaUp = strtoupper($diaTitulo);
$supUp = '';
if ($supervisorParam !== '') {
   $supShown = $supervisorParam;
   if (strpos($supShown, ' - ') !== false) { $supShown = trim(explode(' - ', $supShown, 2)[1]); }
   $supUp = strtoupper($supShown);
}

echo '<div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:8px;">';
echo '<div style="min-width:260px;"><b>Total documentos:</b> ' . number_format($total_global, 0, '.', ',') . '</div>';
echo '<div style="flex:1; text-align:center; font-weight:800; font-size:20px;">' . htmlspecialchars(trim($diaUp . ($supUp?(' - ' . $supUp):'')), ENT_QUOTES, 'UTF-8') . '</div>';
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
