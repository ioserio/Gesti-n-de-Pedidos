<?php
require_once __DIR__ . '/require_login.php';
require_once __DIR__ . '/init.php';
// devoluciones_gestion.php
// Gestión editable de devoluciones por fecha agrupadas por camión (vehículo)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/conexion.php';

function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Detectar si la solicitud espera JSON (AJAX)
function wants_json(): bool {
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $acceptsJson = isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;
    return $isAjax || $acceptsJson;
}

// Asegurar tabla de estados por unidad usando el esquema solicitado (con FK y ENUM)
$createEstado = "CREATE TABLE IF NOT EXISTS `devoluciones_estado` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `devolucion_id` INT UNSIGNED NOT NULL,
    `unidad_index` INT UNSIGNED NOT NULL,
    `estado` ENUM('OK','No llego al almacen','Sin compra','No autorizado','No digitado') NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_dev_unit` (`devolucion_id`, `unidad_index`),
    KEY `idx_dev` (`devolucion_id`),
    CONSTRAINT `fk_dev_estado`
        FOREIGN KEY (`devolucion_id`) REFERENCES `devoluciones_por_cliente`(`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$mysqli->query($createEstado);

// Intentar ajustar el ENUM si la tabla ya existía sin las opciones nuevas
@$mysqli->query("ALTER TABLE `devoluciones_estado` MODIFY COLUMN `estado` ENUM('OK','No llego al almacen','Sin compra','No autorizado','No digitado') NOT NULL");

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'view';

// Endpoint: opciones de selección (vendedores, clientes, productos)
if ($action === 'options' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $fecha = isset($_GET['fecha']) ? trim($_GET['fecha']) : '';
    // Opcionalmente filtrar por fecha para acotar listas; si no se envía, traer de todo el histórico (limitado)
    $where = [];
    $types = '';
    $params = [];
    if ($fecha !== '') { $where[] = 'fecha = ?'; $types .= 's'; $params[] = $fecha; }
    $w = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Helper para consultas distinct con límite prudente
    $data = ['vendedores'=>[], 'clientes'=>[], 'productos'=>[]];
    // Vendedores
    $sqlV = "SELECT DISTINCT codigovendedor, COALESCE(NULLIF(TRIM(nombrevendedor), ''), '') AS nombre FROM devoluciones_por_cliente $w ORDER BY codigovendedor LIMIT 500";
    $stmtV = $types ? $mysqli->prepare($sqlV) : $mysqli->prepare($sqlV);
    if ($types) $stmtV->bind_param($types, ...$params);
    $stmtV->execute();
    $resV = $stmtV->get_result();
    while ($r = $resV->fetch_assoc()) { $data['vendedores'][] = ['code'=>$r['codigovendedor'], 'name'=>$r['nombre']]; }
    $stmtV->close();
    // Clientes
    $sqlC = "SELECT DISTINCT codigocliente, COALESCE(NULLIF(TRIM(nombrecliente), ''), '') AS nombre FROM devoluciones_por_cliente $w ORDER BY codigocliente LIMIT 1000";
    $stmtC = $types ? $mysqli->prepare($sqlC) : $mysqli->prepare($sqlC);
    if ($types) $stmtC->bind_param($types, ...$params);
    $stmtC->execute();
    $resC = $stmtC->get_result();
    while ($r = $resC->fetch_assoc()) { $data['clientes'][] = ['code'=>$r['codigocliente'], 'name'=>$r['nombre']]; }
    $stmtC->close();
    // Productos
    $sqlP = "SELECT DISTINCT codigoproducto, COALESCE(NULLIF(TRIM(nombreproducto), ''), '') AS nombre FROM devoluciones_por_cliente $w ORDER BY codigoproducto LIMIT 1000";
    $stmtP = $types ? $mysqli->prepare($sqlP) : $mysqli->prepare($sqlP);
    if ($types) $stmtP->bind_param($types, ...$params);
    $stmtP->execute();
    $resP = $stmtP->get_result();
    while ($r = $resP->fetch_assoc()) { $data['productos'][] = ['code'=>$r['codigoproducto'], 'name'=>$r['nombre']]; }
    $stmtP->close();

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true] + $data);
    exit;
}

// Endpoint: crear manualmente una línea de devolución dentro de un camión
if ($action === 'create_manual' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $fecha = isset($_POST['fecha']) ? trim($_POST['fecha']) : '';
    $vehiculo = isset($_POST['vehiculo']) ? trim($_POST['vehiculo']) : '';
    if ($vehiculo === 'SIN VEHICULO') $vehiculo = '';
    $codVend = isset($_POST['codigovendedor']) ? trim($_POST['codigovendedor']) : '';
    $nomVend = isset($_POST['nombrevendedor']) ? trim($_POST['nombrevendedor']) : '';
    $codCli = isset($_POST['codigocliente']) ? trim($_POST['codigocliente']) : '';
    $nomCli = isset($_POST['nombrecliente']) ? trim($_POST['nombrecliente']) : '';
    $codProd = isset($_POST['codigoproducto']) ? trim($_POST['codigoproducto']) : '';
    $nomProd = isset($_POST['nombreproducto']) ? trim($_POST['nombreproducto']) : '';
    $cantidad = isset($_POST['cantidad']) ? (float)$_POST['cantidad'] : 0;

    if ($fecha === '' || $codVend === '' || $codCli === '' || $codProd === '' || $cantidad <= 0) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false, 'message'=>'Datos incompletos']);
        exit;
    }

    // Completar nombres si no fueron provistos, usando últimos valores conocidos
    if ($nomVend === '') {
        $stmt = $mysqli->prepare('SELECT nombrevendedor FROM devoluciones_por_cliente WHERE codigovendedor = ? AND nombrevendedor IS NOT NULL AND nombrevendedor <> "" ORDER BY id DESC LIMIT 1');
        $stmt->bind_param('s', $codVend);
        $stmt->execute();
        $stmt->bind_result($nv); if ($stmt->fetch()) $nomVend = (string)$nv; $stmt->close();
    }
    if ($nomCli === '') {
        $stmt = $mysqli->prepare('SELECT nombrecliente FROM devoluciones_por_cliente WHERE codigocliente = ? AND nombrecliente IS NOT NULL AND nombrecliente <> "" ORDER BY id DESC LIMIT 1');
        $stmt->bind_param('s', $codCli);
        $stmt->execute();
        $stmt->bind_result($nc); if ($stmt->fetch()) $nomCli = (string)$nc; $stmt->close();
    }
    if ($nomProd === '') {
        $stmt = $mysqli->prepare('SELECT nombreproducto FROM devoluciones_por_cliente WHERE codigoproducto = ? AND nombreproducto IS NOT NULL AND nombreproducto <> "" ORDER BY id DESC LIMIT 1');
        $stmt->bind_param('s', $codProd);
        $stmt->execute();
        $stmt->bind_result($np); if ($stmt->fetch()) $nomProd = (string)$np; $stmt->close();
    }

    $stmtI = $mysqli->prepare('INSERT INTO devoluciones_por_cliente (fecha, codigovendedor, nombrevendedor, codigocliente, nombrecliente, codigoproducto, nombreproducto, cantidad, vehiculo) VALUES (?,?,?,?,?,?,?,?,?)');
    $stmtI->bind_param('sssssssds', $fecha, $codVend, $nomVend, $codCli, $nomCli, $codProd, $nomProd, $cantidad, $vehiculo);
    if (!$stmtI->execute()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false, 'message'=>'No se pudo insertar: ' . $stmtI->error]);
        exit;
    }
    $newId = $stmtI->insert_id;
    $stmtI->close();

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true, 'id'=>$newId]);
    exit;
}

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
    $permitidos = ['OK','No llego al almacen','Sin compra','No autorizado','No digitado'];
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

    // Recalcular conteos por estado para la devolución
    $counts = ['OK'=>0,'No llego al almacen'=>0,'Sin compra'=>0,'No autorizado'=>0,'No digitado'=>0,'otros'=>0];
    $stmtC = $mysqli->prepare('SELECT estado, COUNT(*) c FROM devoluciones_estado WHERE devolucion_id = ? GROUP BY estado');
    $stmtC->bind_param('i', $devId);
    $stmtC->execute();
    $resC = $stmtC->get_result();
    $asignados = 0;
    while ($rc = $resC->fetch_assoc()) {
        $st = (string)$rc['estado'];
        $c = (int)$rc['c'];
        if (isset($counts[$st])) { $counts[$st] = $c; } else { $counts['otros'] += $c; }
        $asignados += $c;
    }
    $stmtC->close();
    $quedan = max(0, $cantTotal - $asignados);

    $message = 'Asignadas ' . $cantAsignar . ' unidad(es) como ' . $estado . '. Restantes: ' . $quedan . '.';
    if (wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'devolucion_id' => $devId,
            'assigned' => $cantAsignar,
            'estado' => $estado,
            'counts' => $counts,
            'restantes' => $quedan,
            'asignados' => $asignados,
            'total' => $cantTotal,
            'message' => $message,
        ]);
    } else {
        echo '<div class="msg-ok">' . esc($message) . '</div>';
    }
    exit;
}

// Endpoint: asignar en bloque TODOS los restantes de múltiples devoluciones (optimiza OK_Restantes)
if ($action === 'bulk_restantes' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Espera JSON: items: [ { devolucion_id, estado } ] (estado elegido por fila)
    $raw = file_get_contents('php://input');
    $permitidos = ['OK','No llego al almacen','Sin compra','No autorizado','No digitado'];
    $payload = json_decode($raw, true);
    if (!is_array($payload) || !isset($payload['items']) || !is_array($payload['items'])) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'message'=>'Formato inválido']);
        exit;
    }
    $items = $payload['items'];
    // Normalizar lista de IDs únicos válidos
    $mapEstadoPorDev = [];
    foreach ($items as $it) {
        if (!isset($it['devolucion_id'])) continue;
        $devId = (int)$it['devolucion_id'];
        if ($devId <= 0) continue;
        $estado = isset($it['estado']) ? trim((string)$it['estado']) : 'OK';
        if (!in_array($estado, $permitidos, true)) $estado = 'OK';
        $mapEstadoPorDev[$devId] = $estado; // último gana si hay duplicados
    }
    if (empty($mapEstadoPorDev)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'message'=>'Sin devoluciones válidas']);
        exit;
    }
    $devIds = array_keys($mapEstadoPorDev);
    // Obtener cantidades totales de cada devolución
    $place = implode(',', array_fill(0, count($devIds), '?'));
    $types = str_repeat('i', count($devIds));
    $stmtQty = $mysqli->prepare("SELECT id, cantidad FROM devoluciones_por_cliente WHERE id IN ($place)");
    $stmtQty->bind_param($types, ...$devIds);
    $stmtQty->execute();
    $resQty = $stmtQty->get_result();
    $cantPorDev = [];
    while ($r = $resQty->fetch_assoc()) {
        $cantPorDev[(int)$r['id']] = (int)round((float)$r['cantidad']);
    }
    $stmtQty->close();
    // Obtener índices ya usados para todas las devoluciones
    $stmtUsed = $mysqli->prepare("SELECT devolucion_id, unidad_index FROM devoluciones_estado WHERE devolucion_id IN ($place)");
    $stmtUsed->bind_param($types, ...$devIds);
    $stmtUsed->execute();
    $resUsed = $stmtUsed->get_result();
    $usadosPorDev = [];
    while ($r = $resUsed->fetch_assoc()) {
        $d = (int)$r['devolucion_id'];
        $i = (int)$r['unidad_index'];
        $usadosPorDev[$d][$i] = true;
    }
    $stmtUsed->close();

    $mysqli->begin_transaction();
    $stmtIns = $mysqli->prepare('INSERT INTO devoluciones_estado (devolucion_id, unidad_index, estado) VALUES (?,?,?)');
    $resultados = [];
    try {
        foreach ($mapEstadoPorDev as $devId => $estado) {
            $total = isset($cantPorDev[$devId]) ? (int)$cantPorDev[$devId] : 0;
            if ($total < 1) $total = 1;
            $usados = isset($usadosPorDev[$devId]) ? $usadosPorDev[$devId] : [];
            // Calcular restantes
            $restantesIdx = [];
            for ($i=1; $i <= $total; $i++) {
                if (!isset($usados[$i])) $restantesIdx[] = $i;
            }
            $cantRestantes = count($restantesIdx);
            $assignedNow = 0;
            if ($cantRestantes > 0) {
                foreach ($restantesIdx as $idx) {
                    $stmtIns->bind_param('iis', $devId, $idx, $estado);
                    if (!$stmtIns->execute()) throw new Exception('Error asignando: ' . $stmtIns->error);
                    $assignedNow++;
                }
            }
            // Recalcular conteos finales para esta devolución
            $counts = ['OK'=>0,'No llego al almacen'=>0,'Sin compra'=>0,'No autorizado'=>0,'No digitado'=>0,'otros'=>0];
            $stmtC = $mysqli->prepare('SELECT estado, COUNT(*) c FROM devoluciones_estado WHERE devolucion_id = ? GROUP BY estado');
            $stmtC->bind_param('i', $devId);
            $stmtC->execute();
            $resC = $stmtC->get_result();
            $asignados = 0;
            while ($rc = $resC->fetch_assoc()) {
                $st = (string)$rc['estado'];
                $c = (int)$rc['c'];
                if (isset($counts[$st])) { $counts[$st] = $c; } else { $counts['otros'] += $c; }
                $asignados += $c;
            }
            $stmtC->close();
            $quedan = max(0, $total - $asignados);
            $resultados[] = [
                'devolucion_id' => $devId,
                'estado' => $estado,
                'assigned' => $assignedNow,
                'counts' => $counts,
                'restantes' => $quedan,
                'asignados' => $asignados,
                'total' => $total,
            ];
        }
        $mysqli->commit();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>true,'resultados'=>$resultados]);
    } catch (Throwable $e) {
        $mysqli->rollback();
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

if ($action === 'undo_all' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Deshacer TODO: eliminar todas las asignaciones de esa devolución
    $devId = isset($_POST['devolucion_id']) ? (int)$_POST['devolucion_id'] : 0;
    if ($devId <= 0) { http_response_code(400); echo 'Parámetros inválidos'; exit; }

    // Cantidad total de esa devolución
    $stmt = $mysqli->prepare('SELECT cantidad FROM devoluciones_por_cliente WHERE id = ?');
    $stmt->bind_param('i', $devId);
    $stmt->execute();
    $stmt->bind_result($cantTotal);
    if (!$stmt->fetch()) { $stmt->close(); http_response_code(404); echo 'Devolución no encontrada'; exit; }
    $stmt->close();
    $cantTotal = (int)round((float)$cantTotal);
    if ($cantTotal < 1) $cantTotal = 1;

    // Borrar todas las asignaciones para la devolución
    $stmtD = $mysqli->prepare('DELETE FROM devoluciones_estado WHERE devolucion_id = ?');
    $stmtD->bind_param('i', $devId);
    if (!$stmtD->execute()) { http_response_code(500); echo 'Error al deshacer: ' . esc($stmtD->error); exit; }
    $removidas = $stmtD->affected_rows;
    $stmtD->close();

    // Tras borrar todo, conteos quedan a cero y restantes = total
    $counts = ['OK'=>0,'No llego al almacen'=>0,'Sin compra'=>0,'No autorizado'=>0,'No digitado'=>0,'otros'=>0];
    $asignados = 0;
    $quedan = $cantTotal;

    $message = 'Se deshicieron todas las asignaciones. Restantes: ' . $quedan . '.';
    if (wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'devolucion_id' => $devId,
            'removed' => (int)$removidas,
            'counts' => $counts,
            'restantes' => $quedan,
            'asignados' => $asignados,
            'total' => $cantTotal,
            'message' => $message,
        ]);
    } else {
        echo '<div class="msg-ok">' . esc($message) . '</div>';
    }
    exit;
}

if ($action === 'vehiculos' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    // Listar vehículos del día con su estado de completitud (asignados vs total)
    $fecha = isset($_GET['fecha']) ? trim($_GET['fecha']) : '';
    $codVend = isset($_GET['cod_vendedor']) ? trim($_GET['cod_vendedor']) : '';
    $codCli = isset($_GET['cod_cliente']) ? trim($_GET['cod_cliente']) : '';
    if ($fecha === '') { http_response_code(400); echo 'Fecha requerida'; exit; }

    $where = ['d.fecha = ?'];
    $types = 's';
    $params = [$fecha];
    if ($codVend !== '') { $where[] = 'd.codigovendedor = ?'; $types .= 's'; $params[] = $codVend; }
    if ($codCli !== '') { $where[] = 'd.codigocliente = ?'; $types .= 's'; $params[] = $codCli; }

    // 1) Totales por vehículo (sin joins para no inflar valores)
    $sqlTot = 'SELECT COALESCE(NULLIF(TRIM(vehiculo), \'\'), \'\') AS veh,
                      SUM(ROUND(cantidad)) AS total
               FROM devoluciones_por_cliente d
               WHERE ' . implode(' AND ', $where) . '
               GROUP BY veh';
    $stmtT = $mysqli->prepare($sqlTot);
    $stmtT->bind_param($types, ...$params);
    $stmtT->execute();
    $resT = $stmtT->get_result();
    $map = [];
    while ($r = $resT->fetch_assoc()) {
        $veh = (string)$r['veh'];
        $map[$veh] = [ 'total' => max(0, (int)$r['total']), 'asignados' => 0 ];
    }
    $stmtT->close();

    // 2) Asignados por vehículo (contando unidades en devoluciones_estado)
    $sqlAsg = 'SELECT COALESCE(NULLIF(TRIM(d.vehiculo), \'\'), \'\') AS veh,
                      COUNT(e.id) AS asignados
               FROM devoluciones_estado e
               JOIN devoluciones_por_cliente d ON d.id = e.devolucion_id
               WHERE ' . implode(' AND ', $where) . '
               GROUP BY veh';
    $stmtA = $mysqli->prepare($sqlAsg);
    $stmtA->bind_param($types, ...$params);
    $stmtA->execute();
    $resA = $stmtA->get_result();
    while ($r = $resA->fetch_assoc()) {
        $veh = (string)$r['veh'];
        $asg = max(0, (int)$r['asignados']);
        if (!isset($map[$veh])) { $map[$veh] = ['total'=>0, 'asignados'=>0]; }
        $map[$veh]['asignados'] = $asg;
    }
    $stmtA->close();

    // 3) Construir respuesta ordenada por veh
    ksort($map, SORT_NATURAL | SORT_FLAG_CASE);
    $data = [];
    foreach ($map as $veh => $vals) {
        $total = (int)$vals['total'];
        $asign = (int)$vals['asignados'];
        $label = ($veh === '') ? 'SIN VEHICULO' : $veh;
        $complete = ($total > 0) ? ($asign >= $total) : true;
        $data[] = [
            'value' => $veh,
            'label' => $label,
            'total' => $total,
            'asignados' => $asign,
            'complete' => $complete,
        ];
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true, 'vehiculos'=>$data]);
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
    'Sin compra' => 'Sin compra',
    'No autorizado' => 'No autorizado',
    'No digitado' => 'No digitado',
];

ob_start();
foreach ($porVeh as $vehiculo => $lista) {
    echo '<div class="bloque-vehiculo">';
    echo '<div style="display:flex; align-items:center; justify-content:space-between; gap:10px;">';
    echo '<h3 style="margin:6px 0;">Camión ' . esc($vehiculo) . '</h3>';
    echo '<div style="display:flex; gap:8px;">';
    echo '<button type="button" class="btn-add-line" data-vehiculo="' . esc($vehiculo) . '"'
        . ' title="Agregar una línea manual al camión"'
        . ' style="background:#0d6efd;color:#fff;border:none;padding:6px 10px;border-radius:4px;cursor:pointer;">Add+</button>';
    echo '<button type="button" class="btn-ok-restantes" data-vehiculo="' . esc($vehiculo) . '"'
        . ' style="background:#198754;color:#fff;border:none;padding:6px 10px;border-radius:4px;cursor:pointer;">OK_Restantes</button>';
    echo '</div>';
    echo '</div>';
    echo '<table>';
    echo '<thead><tr>'
        .'<th>Cod_Vend</th><th>Cod_Cliente</th><th>Nombre Cliente</th><th>Cod_Prod</th><th>Producto</th><th>Cantidad</th><th>Estados</th>'
        .'</tr></thead><tbody>';
    foreach ($lista as $r) {
        $id = (int)$r['id'];
        $cant = (int)round((float)$r['cantidad']);
        if ($cant < 1) $cant = 1;
        // Resumen de estados ya asignados
    $counts = ['OK'=>0,'No llego al almacen'=>0,'Sin compra'=>0,'No autorizado'=>0,'No digitado'=>0,'__otros'=>0];
        $asignados = 0;
        if (isset($estadosMap[$id])) {
            foreach ($estadosMap[$id] as $st) {
                if (isset($counts[$st])) { $counts[$st]++; } else { $counts['__otros']++; }
                $asignados++;
            }
        }
        $restan = max(0, $cant - $asignados);

        // Construir filas en buffer para poder adjuntar 'Deshacer' cuando no haya pendientes
        $bufferRows = [];
        $clasificaciones = [
            'OK' => $counts['OK'],
            'Sin compra' => $counts['Sin compra'],
            'No llego al almacen' => $counts['No llego al almacen'],
            'No autorizado' => $counts['No autorizado'],
            'No digitado' => $counts['No digitado'],
        ];
        foreach ($clasificaciones as $nombre => $num) {
            if ($num <= 0) continue;
            $bufferRows[] = [ 'estado' => $nombre, 'cantidad' => $num ];
        }
        if ($counts['__otros'] > 0) {
            $bufferRows[] = [ 'estado' => 'Otros', 'cantidad' => (int)$counts['__otros'] ];
        }

        $printed = 0; $totalRows = count($bufferRows);
        foreach ($bufferRows as $rowInfo) {
            $printed++;
            $isLast = ($printed === $totalRows);
            echo '<tr class="dev-row dev-row-class" data-dev-id="' . $id . '" data-estado="' . esc($rowInfo['estado']) . '">';
            echo '<td>' . esc($r['codigovendedor']) . '</td>';
            echo '<td>' . esc($r['codigocliente']) . '</td>';
            echo '<td>' . esc($r['nombrecliente']) . '</td>';
            echo '<td>' . esc($r['codigoproducto']) . '</td>';
            echo '<td>' . esc($r['nombreproducto']) . '</td>';
            echo '<td style="text-align:right;">' . esc($rowInfo['cantidad']) . '</td>';
            echo '<td>' . esc($rowInfo['estado']);
            // Si no hay pendientes y hay algo asignado, mostrar Deshacer en la última fila de clasificación
            if ($restan === 0 && $asignados > 0 && $isLast) {
                echo ' &nbsp; ';
                echo '<form class="form-bulk" method="post" action="devoluciones_gestion.php" style="display:inline-block; margin-left:6px;">'
                    . '<input type="hidden" name="action" value="undo_all">'
                    . '<input type="hidden" name="devolucion_id" value="' . $id . '">'
                    . '<button type="submit" data-undo="1" class="btn-undo" style="background:#dc3545;color:#fff;border:none;padding:4px 8px;border-radius:4px;">Deshacer</button>'
                    . '</form>';
            }
            echo '</td>';
            echo '</tr>';
        }

        // Fila de pendientes/acciones (solo si restan unidades)
        if ($restan > 0) {
            echo '<tr class="dev-row dev-row-pend" data-dev-id="' . $id . '">';
            echo '<td>' . esc($r['codigovendedor']) . '</td>';
            echo '<td>' . esc($r['codigocliente']) . '</td>';
            echo '<td>' . esc($r['nombrecliente']) . '</td>';
            echo '<td>' . esc($r['codigoproducto']) . '</td>';
            echo '<td>' . esc($r['nombreproducto']) . '</td>';
            echo '<td style="text-align:right;">' . esc($restan) . '</td>';
            echo '<td>';
            // Formulario masivo por fila (pendientes)
            echo '<form class="form-bulk" method="post" action="devoluciones_gestion.php" style="margin-top:6px; display:flex; gap:6px; align-items:center; flex-wrap:wrap;">'
                . '<input type="hidden" name="action" value="add_bulk">'
                . '<input type="hidden" name="devolucion_id" value="' . $id . '">'
                . '<label>Cant: <input type="number" name="cantidad" min="1" max="' . esc($restan) . '" value="' . ($restan>0?$restan:0) . '" ' . ($restan>0?'':'disabled') . ' style="width:80px; padding:4px;"></label>'
                . '<select name="estado" ' . ($restan>0?'':'disabled') . ' class="estado-select">';
            foreach ($opciones as $val => $label) {
                if ($val==='') continue;
                echo '<option value="' . esc($val) . '">' . esc($label) . '</option>';
            }
            echo '</select>'
                . '<button type="submit" ' . ($restan>0?'':'disabled') . '>Agregar</button>'
                . '<button type="submit" data-undo="1" class="btn-undo" ' . ($asignados>0?'':'disabled') . ' style="background:#dc3545;color:#fff;border:none;padding:6px 10px;border-radius:4px;">Deshacer</button>'
                . '</form>'
                . '<div class="msg-ajax" style="margin-top:4px;"></div>';
            echo '</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table>';
    echo '</div>';
}
$html = ob_get_clean();

echo $html ?: '<p>No hay devoluciones para la fecha seleccionada.</p>';
