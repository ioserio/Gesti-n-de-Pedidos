<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/conexion.php';

function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$fecha = isset($_GET['fecha']) ? trim($_GET['fecha']) : '';
$fechaDesde = isset($_GET['fecha_desde']) ? trim($_GET['fecha_desde']) : '';
$fechaHasta = isset($_GET['fecha_hasta']) ? trim($_GET['fecha_hasta']) : '';
$vd = isset($_GET['cod_vendedor']) ? trim($_GET['cod_vendedor']) : '';
$onlyPending = isset($_GET['pendientes']) && ($_GET['pendientes'] === '1' || strtolower($_GET['pendientes']) === 'true');
// Traer devoluciones con filtros opcionales (fecha exacta o rango) y vendedor
$where = [];
$types = '';
$params = [];
if ($fecha !== '') {
    $where[] = 'd.fecha = ?'; $types .= 's'; $params[] = $fecha;
} else {
    if ($fechaDesde !== '') { $where[] = 'd.fecha >= ?'; $types .= 's'; $params[] = $fechaDesde; }
    if ($fechaHasta !== '') { $where[] = 'd.fecha <= ?'; $types .= 's'; $params[] = $fechaHasta; }
}
if ($vd !== '')    { $where[] = 'TRIM(d.codigovendedor) = ?'; $types .= 's'; $params[] = $vd; }
if (empty($where)) { $where[] = '1=1'; }

$sql = 'SELECT d.id, d.fecha, COALESCE(NULLIF(TRIM(d.vehiculo), \'\'), \'SIN VEHICULO\') as vehiculo,
           TRIM(d.codigovendedor) as codigovendedor, d.nombrevendedor,
           d.codigocliente, d.nombrecliente,
           d.codigoproducto, d.nombreproducto,
           ROUND(d.cantidad) as cantidad
    FROM devoluciones_por_cliente d
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY vehiculo, d.fecha, d.codigocliente, d.codigoproducto';
$stmt = $mysqli->prepare($sql);
if ($types !== '') { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$res = $stmt->get_result();
$rows = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (!$rows){ echo '<p>No hay recojos para los criterios indicados.</p>'; exit; }

// Traer conteos de estados por devolucion_id en bloque
$ids = array_column($rows, 'id');
$estadosMap = [];
if (!empty($ids)) {
    $place = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmtE = $mysqli->prepare("SELECT devolucion_id, estado, COUNT(*) c FROM devoluciones_estado WHERE devolucion_id IN ($place) GROUP BY devolucion_id, estado");
    $stmtE->bind_param($types, ...$ids);
    $stmtE->execute();
    $resE = $stmtE->get_result();
    while ($r = $resE->fetch_assoc()) {
        $d = (int)$r['devolucion_id'];
        $st = (string)$r['estado'];
        $c  = (int)$r['c'];
        $estadosMap[$d][$st] = $c;
    }
    $stmtE->close();
}

// Agrupar por vehículo
$porVeh = [];
foreach ($rows as $r) {
    $key = trim((string)$r['vehiculo']);
    if ($key === '') $key = 'SIN VEHICULO';
    $porVeh[$key][] = $r;
}

ob_start();
foreach ($porVeh as $veh => $lista) {
    echo '<div class="bloque-vehiculo">';
    echo '<h3>Camión ' . esc($veh) . '</h3>';
    echo '<table>';
    echo '<thead><tr>'
        .'<th>Fecha</th><th>Cod_Cliente</th><th>Nombre Cliente</th><th>Cod_Prod</th><th>Producto</th><th>Cantidad</th><th>Estado</th>'
        .'</tr></thead><tbody>';
    $mostro = false;
    foreach ($lista as $r) {
        $id = (int)$r['id'];
        $cant = (int)$r['cantidad'];
        $counts = ['OK'=>0,'No llego al almacen'=>0,'Sin compra'=>0];
        if (isset($estadosMap[$id])) {
            foreach ($estadosMap[$id] as $st => $c) { if (isset($counts[$st])) $counts[$st] = (int)$c; }
        }
        $asignados = $counts['OK'] + $counts['No llego al almacen'] + $counts['Sin compra'];
        $restan = max(0, $cant - $asignados);
        $restanOk = max(0, $cant - $counts['OK']); // unidades que aún NO están en OK
        // Si filtramos pendientes, sólo mostrar items donde todavía faltan OK
        if ($onlyPending && $restanOk <= 0) { continue; }

        // Imprimir sub-líneas por clasificación y una de pendientes si aplica (como en gestión)
        // Si está activo "pendientes", ocultamos la línea OK y mostramos sólo no-OK y Pendientes
        $lineas = [];
        if (!$onlyPending && $counts['OK'] > 0) {
            $lineas[] = ['estado' => 'OK', 'cantidad' => $counts['OK']];
        }
        if ($counts['Sin compra'] > 0) $lineas[] = ['estado' => 'Sin compra', 'cantidad' => $counts['Sin compra']];
        if ($counts['No llego al almacen'] > 0) $lineas[] = ['estado' => 'No llego al almacen', 'cantidad' => $counts['No llego al almacen']];
        if ($restan > 0) $lineas[] = ['estado' => 'Pendientes', 'cantidad' => $restan];

        if (empty($lineas)) {
            // Nada que mostrar (raro, pero por seguridad)
            continue;
        }
        $mostro = true;
        foreach ($lineas as $ln) {
            echo '<tr>';
            echo '<td>' . esc($r['fecha']) . '</td>';
            echo '<td>' . esc($r['codigocliente']) . '</td>';
            echo '<td>' . esc($r['nombrecliente']) . '</td>';
            echo '<td>' . esc($r['codigoproducto']) . '</td>';
            echo '<td>' . esc($r['nombreproducto']) . '</td>';
            echo '<td style="text-align:right;">' . esc($ln['cantidad']) . '</td>';
            // Mostrar "Restan OK" como badge cuando filtramos pendientes o cuando estado no es OK
            $badge = ($restanOk > 0 && $ln['estado'] !== 'OK') ? ' <span style="color:#555; font-size:12px;">(Restan OK: ' . esc($restanOk) . ')</span>' : '';
            echo '<td>' . esc($ln['estado']) . $badge . '</td>';
            echo '</tr>';
        }
    }
    if (!$mostro) {
        echo '<tr><td colspan="7" style="text-align:center; color:#666;">' . ($onlyPending ? 'Sin pendientes en este camión' : 'Sin resultados en este camión') . '</td></tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
}
$html = ob_get_clean();

echo $html ?: '<p>Sin resultados.</p>';
