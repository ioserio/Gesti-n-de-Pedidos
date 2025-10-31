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
$cliente = isset($_GET['cliente']) ? trim($_GET['cliente']) : '';
$supervisor = isset($_GET['supervisor']) ? trim($_GET['supervisor']) : '';
$onlyPending = isset($_GET['pendientes']) && ($_GET['pendientes'] === '1' || strtolower($_GET['pendientes']) === 'true');
// Mapa VD => SUPERVISOR (para filtros internos)
$vd_supervisor = [
    '011'=>'CARLOS','016'=>'CARLOS','023'=>'JOSE','026'=>'JESUS','028'=>'CARLOS','029'=>'CARLOS','030'=>'CARLOS',
    '102'=>'JESUS','104'=>'JOSE','106'=>'JESUS','108'=>'JOSE','110'=>'JESUS','113'=>'JESUS','114'=>'JESUS','118'=>'JESUS',
    '997'=>'FRANCISCO','777'=>'OFICINA','999'=>'OFICINA','004'=>'JOSE','005'=>'CARLOS','012'=>'JOSE','014'=>'JESUS',
    '018'=>'JESUS','024'=>'FRANCISCO','025'=>'FRANCISCO','027'=>'CARLOS','101'=>'JOSE','103'=>'JOSE','105'=>'CARLOS',
    '107'=>'JOSE','109'=>'JESUS','606'=>'FRANCISCO','607'=>'FRANCISCO','001'=>'JOSE','002'=>'JOSE','003'=>'JOSE',
    '007'=>'JOSE','008'=>'JESUS','009'=>'JESUS','010'=>'JOSE','013'=>'JESUS','021'=>'CARLOS','022'=>'CARLOS',
    '115'=>'CARLOS','116'=>'CARLOS','119'=>'CARLOS','604'=>'FRANCISCO','605'=>'FRANCISCO'
];
// Precomputar conjunto de VDs del supervisor seleccionado (si aplica)
$vendSupSet = null;
if ($supervisor !== '') {
    $vendSupSet = [];
    foreach ($vd_supervisor as $code => $sup) {
        if ($sup === $supervisor) { $vendSupSet[$code] = true; }
    }
}
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
if ($cliente !== '') {
    // Buscar por código exacto o nombre parcial (case-insensitive)
    $where[] = '(TRIM(d.codigocliente) = ? OR LOWER(TRIM(d.nombrecliente)) LIKE ?)';
    $types .= 'ss';
    $params[] = $cliente;
    $params[] = '%' . mb_strtolower($cliente, 'UTF-8') . '%';
}
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

// Tabla plana; reordenamos columnas para que Fecha sea la primera
ob_start();
echo '<table>';
echo '<thead><tr>'
    .'<th>Fecha</th><th>Camión</th><th>Cod_Vend</th><th>Cod_Cliente</th><th>Nombre Cliente</th><th>Cod_Prod</th><th>Producto</th><th>Cantidad</th><th>Estado</th>'
    .'</tr></thead><tbody>';

$mostro = false;
foreach ($rows as $r) {
    // Filtrar por supervisor si se seleccionó
    if (!empty($supervisor) && is_array($vendSupSet)) {
        $vdRaw = trim((string)$r['codigovendedor']);
        $vdNoZeros = ltrim($vdRaw, '0');
        if ($vdNoZeros === '') { $vdNoZeros = '0'; }
        $vdPadded3 = str_pad($vdNoZeros, 3, '0', STR_PAD_LEFT);
        if (!isset($vendSupSet[$vdRaw]) && !isset($vendSupSet[$vdNoZeros]) && !isset($vendSupSet[$vdPadded3])) {
            continue;
        }
    }
    $id = (int)$r['id'];
    $cant = (int)$r['cantidad'];
    $veh = trim((string)$r['vehiculo']);
    if ($veh === '') $veh = 'SIN VEHICULO';
    $counts = ['OK'=>0,'No llego al almacen'=>0,'Sin compra'=>0,'No autorizado'=>0];
    if (isset($estadosMap[$id])) {
        foreach ($estadosMap[$id] as $st => $c) { if (isset($counts[$st])) $counts[$st] = (int)$c; }
    }
    $asignados = $counts['OK'] + $counts['No llego al almacen'] + $counts['Sin compra'] + $counts['No autorizado'];
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
    if ($counts['No autorizado'] > 0) $lineas[] = ['estado' => 'No autorizado', 'cantidad' => $counts['No autorizado']];
    if ($restan > 0) $lineas[] = ['estado' => 'Pendientes', 'cantidad' => $restan];

    if (empty($lineas)) {
        // Nada que mostrar (raro, pero por seguridad)
        continue;
    }
    $mostro = true;
    foreach ($lineas as $ln) {
        echo '<tr>';
        // Nueva disposición de columnas: Fecha primero, luego Camión y Cod_Vend
        echo '<td>' . esc($r['fecha']) . '</td>';
        echo '<td>' . esc($veh) . '</td>';
        echo '<td>' . esc($r['codigovendedor']) . '</td>';
        echo '<td>' . esc($r['codigocliente']) . '</td>';
        echo '<td>' . esc($r['nombrecliente']) . '</td>';
        echo '<td>' . esc($r['codigoproducto']) . '</td>';
        echo '<td>' . esc($r['nombreproducto']) . '</td>';
        echo '<td style="text-align:right;">' . esc($ln['cantidad']) . '</td>';
        // Ya no mostramos el badge de "Restan OK" en esta tabla
        echo '<td>' . esc($ln['estado']) . '</td>';
        echo '</tr>';
    }
}
if (!$mostro) {
    echo '<tr><td colspan="9" style="text-align:center; color:#666;">' . ($onlyPending ? 'Sin pendientes' : 'Sin resultados') . '</td></tr>';
}
echo '</tbody></table>';
$html = ob_get_clean();

echo $html ?: '<p>Sin resultados.</p>';
