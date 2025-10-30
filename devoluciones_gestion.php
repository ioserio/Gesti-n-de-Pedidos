<?php
// devoluciones_gestion.php
// Gestión editable de devoluciones por fecha agrupadas por camión (vehículo)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/conexion.php';

function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Asegurar tabla de estados por unidad usando el esquema solicitado (con FK y ENUM)
$createEstado = "CREATE TABLE IF NOT EXISTS `devoluciones_estado` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `devolucion_id` INT UNSIGNED NOT NULL,
    `unidad_index` INT UNSIGNED NOT NULL,
    `estado` ENUM('OK','No llego al almacen','Sin compra') NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_dev_unit` (`devolucion_id`, `unidad_index`),
    KEY `idx_dev` (`devolucion_id`),
    CONSTRAINT `fk_dev_estado`
        FOREIGN KEY (`devolucion_id`) REFERENCES `devoluciones_por_cliente`(`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$mysqli->query($createEstado);

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'view';

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Guardar estados enviados: estado[devolucion_id][unidad] = valor
    $fecha = isset($_POST['fecha']) ? trim($_POST['fecha']) : '';
    $vehiculo = isset($_POST['vehiculo']) ? trim($_POST['vehiculo']) : '';
    $estados = isset($_POST['estado']) && is_array($_POST['estado']) ? $_POST['estado'] : [];

    if ($fecha === '') { http_response_code(400); echo 'Fecha requerida'; exit; }
    // Procesar: por cada devolucion_id, borramos sus unidades y reinsertamos las provistas
    $mysqli->begin_transaction();
    try {
        if (!empty($estados)) {
            $ids = array_keys($estados);
            // Limpiar existentes para esos IDs
            $place = implode(',', array_fill(0, count($ids), '?'));
            $types = str_repeat('i', count($ids));
            $stmtDel = $mysqli->prepare("DELETE FROM devoluciones_estado WHERE devolucion_id IN ($place)");
            $stmtDel->bind_param($types, ...$ids);
            $stmtDel->execute();
            $stmtDel->close();

            $stmtIns = $mysqli->prepare("INSERT INTO devoluciones_estado (devolucion_id, unidad_index, estado) VALUES (?,?,?)");
            foreach ($estados as $devId => $porUnidad) {
                if (!is_array($porUnidad)) continue;
                foreach ($porUnidad as $idx => $val) {
                    $val = trim((string)$val);
                    if ($val === '') continue; // no guardar vacíos
                    $devIdInt = (int)$devId; $idxInt = (int)$idx;
                    $stmtIns->bind_param('iis', $devIdInt, $idxInt, $val);
                    if (!$stmtIns->execute()) throw new Exception($stmtIns->error);
                }
            }
            $stmtIns->close();
        }
        $mysqli->commit();
        // Responder mini HTML para mostrar mensaje
        echo '<div class="msg-ok">Estados guardados correctamente.</div>';
    } catch (Throwable $e) {
        $mysqli->rollback();
        http_response_code(500);
        echo '<div class="msg-err">Error guardando estados: ' . esc($e->getMessage()) . '</div>';
    }
    exit;
}

if ($action === 'add_bulk' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Asignar en bloque un estado a N unidades de una devolución
    $devId = isset($_POST['devolucion_id']) ? (int)$_POST['devolucion_id'] : 0;
    $cantAsignar = isset($_POST['cantidad']) ? (int)$_POST['cantidad'] : 0;
    $estado = isset($_POST['estado']) ? trim($_POST['estado']) : '';
    $permitidos = ['OK','No llego al almacen','Sin compra'];
    if (!in_array($estado, $permitidos, true)) { http_response_code(400); echo 'Estado no válido'; exit; }
    if ($devId <= 0 || $cantAsignar <= 0) { http_response_code(400); echo 'Parámetros inválidos'; exit; }

    // Obtener cantidad total para esa devolución
    $stmt = $mysqli->prepare('SELECT cantidad FROM devoluciones_por_cliente WHERE id = ?');
    $stmt->bind_param('i', $devId);
    $stmt->execute();
    $stmt->bind_result($cantTotal);
    if (!$stmt->fetch()) { $stmt->close(); http_response_code(404); echo 'Devolución no encontrada'; exit; }
    $stmt->close();
    $cantTotal = (int)round((float)$cantTotal);
    if ($cantTotal < 1) $cantTotal = 1;

    // Obtener índices ya asignados
    $usados = [];
    $stmt = $mysqli->prepare('SELECT unidad_index FROM devoluciones_estado WHERE devolucion_id = ?');
    $stmt->bind_param('i', $devId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) { $usados[(int)$r['unidad_index']] = true; }
    $stmt->close();

    // Calcular disponibles
    $disp = [];
    for ($i=1; $i <= $cantTotal; $i++) {
        if (!isset($usados[$i])) $disp[] = $i;
    }
    $restantes = count($disp);
    if ($restantes <= 0) { echo '<div class="msg-ok">Todas las unidades ya tienen estado.</div>'; exit; }
    if ($cantAsignar > $restantes) $cantAsignar = $restantes;

    // Insertar las primeras K disponibles
    $stmtIns = $mysqli->prepare('INSERT INTO devoluciones_estado (devolucion_id, unidad_index, estado) VALUES (?,?,?)');
    for ($k=0; $k < $cantAsignar; $k++) {
        $idx = (int)$disp[$k];
        $stmtIns->bind_param('iis', $devId, $idx, $estado);
        if (!$stmtIns->execute()) { http_response_code(500); echo 'Error al asignar: ' . esc($stmtIns->error); exit; }
    }
    $stmtIns->close();
    $quedan = $restantes - $cantAsignar;
    echo '<div class="msg-ok">Asignadas ' . esc($cantAsignar) . ' unidad(es) como ' . esc($estado) . '. Restantes: ' . esc($quedan) . '.</div>';
    exit;
}

// --- Render de vista ---
$fecha = isset($_GET['fecha']) ? trim($_GET['fecha']) : '';
$codVend = isset($_GET['cod_vendedor']) ? trim($_GET['cod_vendedor']) : '';
$codCli = isset($_GET['cod_cliente']) ? trim($_GET['cod_cliente']) : '';
$veh = isset($_GET['vehiculo']) ? trim($_GET['vehiculo']) : '';

if ($fecha === '') { echo '<p>Seleccione una fecha.</p>'; exit; }

$params = [$fecha];
$where = ['fecha = ?'];
$types = 's';
if ($codVend !== '') { $where[] = 'codigovendedor = ?'; $params[] = $codVend; $types .= 's'; }
if ($codCli !== '') { $where[] = 'codigocliente = ?'; $params[] = $codCli; $types .= 's'; }
if ($veh !== '')    { $where[] = 'vehiculo = ?'; $params[] = $veh; $types .= 's'; }

$sql = 'SELECT id, fecha, vehiculo, codigovendedor, nombrevendedor, codigocliente, nombrecliente, codigoproducto, nombreproducto, cantidad
        FROM devoluciones_por_cliente
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY vehiculo, codigovendedor, codigocliente, codigoproducto';
$stmt = $mysqli->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$rows = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Traer estados existentes en bloque
$ids = array_column($rows, 'id');
$estadosMap = [];
if (!empty($ids)) {
    $place = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmtE = $mysqli->prepare("SELECT devolucion_id, unidad_index, estado FROM devoluciones_estado WHERE devolucion_id IN ($place)");
    $stmtE->bind_param($types, ...$ids);
    $stmtE->execute();
    $resE = $stmtE->get_result();
    while ($r = $resE->fetch_assoc()) {
        $d = (int)$r['devolucion_id'];
        $i = (int)$r['unidad_index'];
        $estadosMap[$d][$i] = $r['estado'];
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

$opciones = [
    '' => '-- estado --',
    'OK' => 'OK',
    'No llego al almacen' => 'No llego al almacen',
    'Sin compra' => 'Sin compra'
];

ob_start();
foreach ($porVeh as $vehiculo => $lista) {
    echo '<div class="bloque-vehiculo">';
    echo '<h3>Camión ' . esc($vehiculo) . '</h3>';
    echo '<table>';
    echo '<thead><tr>'
        .'<th>Cod_Vend</th><th>Cod_Cliente</th><th>Nombre Cliente</th><th>Cod_Prod</th><th>Producto</th><th>Cantidad</th><th>Estados</th>'
        .'</tr></thead><tbody>';
    foreach ($lista as $r) {
        $id = (int)$r['id'];
        $cant = (int)round((float)$r['cantidad']);
        if ($cant < 1) $cant = 1;
        // Resumen de estados ya asignados
        $counts = ['OK'=>0,'No llego al almacen'=>0,'Sin compra'=>0,'__otros'=>0];
        $asignados = 0;
        if (isset($estadosMap[$id])) {
            foreach ($estadosMap[$id] as $st) {
                if (isset($counts[$st])) { $counts[$st]++; } else { $counts['__otros']++; }
                $asignados++;
            }
        }
        $restan = max(0, $cant - $asignados);

        echo '<tr id="row-dev-' . $id . '">';
        echo '<td>' . esc($r['codigovendedor']) . '</td>';
        echo '<td>' . esc($r['codigocliente']) . '</td>';
        echo '<td>' . esc($r['nombrecliente']) . '</td>';
        echo '<td>' . esc($r['codigoproducto']) . '</td>';
        echo '<td>' . esc($r['nombreproducto']) . '</td>';
        echo '<td style="text-align:right;">' . esc($cant) . '</td>';
        echo '<td>';
        echo '<div class="estado-resumen">'
            . 'OK: ' . esc($counts['OK'])
            . ' | Sin compra: ' . esc($counts['Sin compra'])
            . ' | No llego al almacen: ' . esc($counts['No llego al almacen'])
            . ($counts['__otros']>0 ? ' | Otros: ' . esc($counts['__otros']) : '')
            . ' | Restan: <strong>' . esc($restan) . '</strong>'
            . '</div>';
        // Formulario masivo por fila
        echo '<form class="form-bulk" method="post" action="devoluciones_gestion.php" style="margin-top:6px; display:flex; gap:6px; align-items:center; flex-wrap:wrap;">'
            . '<input type="hidden" name="action" value="add_bulk">'
            . '<input type="hidden" name="devolucion_id" value="' . $id . '">'
            . '<label>Cant: <input type="number" name="cantidad" min="1" max="' . esc($restan) . '" value="' . ($restan>0?1:0) . '" ' . ($restan>0?'':'disabled') . ' style="width:80px; padding:4px;"></label>'
            . '<select name="estado" ' . ($restan>0?'':'disabled') . ' class="estado-select">';
        foreach ($opciones as $val => $label) {
            if ($val==='') continue;
            echo '<option value="' . esc($val) . '">' . esc($label) . '</option>';
        }
        echo '</select>'
            . '<button type="submit" ' . ($restan>0?'':'disabled') . '>Agregar</button>'
            . '</form>';
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
}
$html = ob_get_clean();

echo $html ?: '<p>No hay devoluciones para la fecha seleccionada.</p>';
