<?php
require_once __DIR__ . '/require_login.php';
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/conexion.php';
header_remove('X-Powered-By');

// Crear tabla principal si no existe
$mysqli->query("CREATE TABLE IF NOT EXISTS almacen_moldes_diarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fecha DATE NOT NULL,
    pedido_numero VARCHAR(32) DEFAULT NULL,
  producto_codigo VARCHAR(32) DEFAULT NULL,
  producto_nombre VARCHAR(255) DEFAULT NULL,
  categoria VARCHAR(64) DEFAULT NULL,
  unidad VARCHAR(16) DEFAULT NULL,
  cantidad DECIMAL(12,2) DEFAULT NULL,
  p_pro DECIMAL(12,2) DEFAULT NULL,
  p_rea DECIMAL(12,2) DEFAULT NULL,
    observacion VARCHAR(255) DEFAULT NULL,
  cliente_codigo VARCHAR(64) DEFAULT NULL,
  cliente_nombre VARCHAR(255) DEFAULT NULL,
  camion VARCHAR(64) DEFAULT NULL,
    cod_vendedor VARCHAR(10) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_fecha (fecha),
    INDEX idx_pedido (pedido_numero),
  INDEX idx_cliente (cliente_codigo),
    INDEX idx_camion (camion),
    INDEX idx_vend (cod_vendedor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Asegurar tabla de mapeo independiente CODIGO -> CAMION
$mysqli->query("CREATE TABLE IF NOT EXISTS almacen_codigo_camion (
    codigo VARCHAR(64) PRIMARY KEY,
    camion VARCHAR(64) DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cam (camion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Asegurar columna cod_vendedor si la tabla existía previamente sin ella
try {
        $res = $mysqli->query("SHOW COLUMNS FROM almacen_moldes_diarios LIKE 'cod_vendedor'");
        if ($res && $res->num_rows === 0) {
                @$mysqli->query("ALTER TABLE almacen_moldes_diarios ADD COLUMN cod_vendedor VARCHAR(10) DEFAULT NULL, ADD INDEX idx_vend (cod_vendedor)");
        }
        if ($res) { $res->close(); }
    // Asegurar columnas nuevas: pedido_numero y observacion
    $res2 = $mysqli->query("SHOW COLUMNS FROM almacen_moldes_diarios LIKE 'pedido_numero'");
    if ($res2 && $res2->num_rows === 0) {
        @$mysqli->query("ALTER TABLE almacen_moldes_diarios ADD COLUMN pedido_numero VARCHAR(32) DEFAULT NULL, ADD INDEX idx_pedido (pedido_numero)");
    }
    if ($res2) { $res2->close(); }
    $res3 = $mysqli->query("SHOW COLUMNS FROM almacen_moldes_diarios LIKE 'observacion'");
    if ($res3 && $res3->num_rows === 0) {
        @$mysqli->query("ALTER TABLE almacen_moldes_diarios ADD COLUMN observacion VARCHAR(255) DEFAULT NULL");
    }
    if ($res3) { $res3->close(); }
} catch (Throwable $e) { /* noop */ }

$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if ($action === 'list') {
    $fecha = isset($_GET['fecha']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['fecha']) ? $_GET['fecha'] : date('Y-m-d');
    $camion = isset($_GET['camion']) ? trim((string)$_GET['camion']) : '';
    $sql = "SELECT m.*, COALESCE(cm.camion, m.camion) AS camion_resolved FROM almacen_moldes_diarios m LEFT JOIN almacen_codigo_camion cm ON cm.codigo = m.producto_codigo WHERE m.fecha=?"; $types = 's'; $params = [$fecha];
    if ($camion !== '') { $sql .= " AND COALESCE(cm.camion, m.camion)=?"; $types .= 's'; $params[] = $camion; }
    $sql .= " ORDER BY m.producto_nombre ASC, m.cliente_nombre ASC";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) { http_response_code(500); echo 'DB error'; exit; }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    ob_start();
    echo '<div style="display:flex; align-items:center; gap:10px; margin-bottom:8px;">'
        .'<strong>Lista de moldes del ' . h($fecha) . '</strong>'
        .($camion !== '' ? '<span style="color:#555">— Camión: ' . h($camion) . '</span>' : '')
        .'</div>';

    if ($res && $res->num_rows > 0) {
        $groups = [];
        while ($row = $res->fetch_assoc()) {
            $key = (string)($row['producto_codigo'] ?? '') . '|' . (string)($row['producto_nombre'] ?? '');
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'codigo' => (string)($row['producto_codigo'] ?? ''),
                    'nombre' => (string)($row['producto_nombre'] ?? ''),
                    'total_cant' => 0.0,
                    'total_ppro' => 0.0,
                    'rows' => []
                ];
            }
            $cant = isset($row['cantidad']) ? (float)$row['cantidad'] : 0.0;
            $pPro = isset($row['p_pro']) ? (float)$row['p_pro'] : 0.0;
            $groups[$key]['total_cant'] += $cant;
            $groups[$key]['total_ppro'] += $pPro;
            $groups[$key]['rows'][] = $row;
        }

        foreach ($groups as $g) {
            echo '<div style="display:flex; align-items:baseline; gap:12px; margin-top:16px; margin-bottom:6px;">'
                .'<strong>' . h($g['nombre']) . '</strong>'
                .'<span style="color:#777">[' . h($g['codigo']) . ']</span>'
                .'<span style="margin-left:auto; color:#333">MOL ' . number_format($g['total_cant'], 0, '.', ',') . '</span>'
                .'</div>';

            echo '<table><thead><tr>'
                .'<th>N° Pedido</th><th>Vendedor</th><th>Cliente código</th><th>Cliente</th><th>Unidad</th><th>Cant.</th><th>P.Pro</th><th>P.Real</th><th>Camión</th><th>Observación</th>'
                .'</tr></thead><tbody>';

            foreach ($g['rows'] as $row) {
                $id = (int)$row['id'];
                $cant = isset($row['cantidad']) ? (float)$row['cantidad'] : null;
                $pPro = isset($row['p_pro']) ? (float)$row['p_pro'] : null;
                $pRea = isset($row['p_rea']) ? (float)$row['p_rea'] : null;
                echo '<tr data-id="'.$id.'">'
                    .'<td>'.h($row['pedido_numero']).'</td>'
                    .'<td>'.h($row['cod_vendedor']).'</td>'
                    .'<td>'.h($row['cliente_codigo']).'</td>'
                    .'<td>'.h($row['cliente_nombre']).'</td>'
                    .'<td>'.h($row['unidad']).'</td>'
                    .'<td>'.($cant!==null?number_format($cant,2,'.',','):'').'</td>'
                    .'<td>'.($pPro!==null?number_format($pPro,2,'.',','):'').'</td>'
                    .'<td><input type="number" step="0.01" min="0" class="alm-prea" value="'.($pRea!==null?htmlspecialchars((string)$pRea,ENT_QUOTES,'UTF-8'):'').'" style="width:100px"></td>'
                    .'<td>'.h($row['camion_resolved']).'</td>'
                    .'<td><input type="text" class="alm-obs" value="'.h($row['observacion']).'" style="width:180px"></td>'
                    .'</tr>';
            }
            echo '</tbody></table>';
        }
    } else {
        echo '<p>No hay registros para la fecha seleccionada. Importa el XLS para comenzar.</p>';
    }
    $html = ob_get_clean();
    header('Content-Type: text/html; charset=utf-8');
    echo $html; exit;
}

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    $has_prea = array_key_exists('p_rea', $_POST);
    $has_obs = array_key_exists('observacion', $_POST);
    $p_rea = $has_prea ? (float)$_POST['p_rea'] : null;
    $obs = $has_obs ? trim((string)$_POST['observacion']) : null;
    if ($id <= 0) { echo json_encode(['ok'=>false,'error'=>'PARAMS']); exit; }
    if ($has_prea && $has_obs) {
        $stmt = $mysqli->prepare('UPDATE almacen_moldes_diarios SET p_rea=?, observacion=? WHERE id=?');
        if (!$stmt) { echo json_encode(['ok'=>false,'error'=>'DB']); exit; }
        $stmt->bind_param('dsi', $p_rea, $obs, $id);
        $ok = $stmt->execute();
    } elseif ($has_prea) {
        $stmt = $mysqli->prepare('UPDATE almacen_moldes_diarios SET p_rea=? WHERE id=?');
        if (!$stmt) { echo json_encode(['ok'=>false,'error'=>'DB']); exit; }
        $stmt->bind_param('di', $p_rea, $id);
        $ok = $stmt->execute();
    } elseif ($has_obs) {
        $stmt = $mysqli->prepare('UPDATE almacen_moldes_diarios SET observacion=? WHERE id=?');
        if (!$stmt) { echo json_encode(['ok'=>false,'error'=>'DB']); exit; }
        $stmt->bind_param('si', $obs, $id);
        $ok = $stmt->execute();
    } else {
        echo json_encode(['ok'=>false,'error'=>'NOPARAM']); exit;
    }
    $stmt->close();
    echo json_encode(['ok'=>$ok?true:false]); exit;
}

// Opcional: lista de camiones disponibles para filtro
if ($action === 'camiones') {
    header('Content-Type: application/json');
    $res = $mysqli->query("SELECT DISTINCT camion FROM almacen_moldes_diarios WHERE camion IS NOT NULL AND camion<>'' ORDER BY camion ASC");
    $cams = [];
    while ($res && ($r = $res->fetch_assoc())) { $cams[] = (string)$r['camion']; }
    if ($res) $res->close();
    echo json_encode(['ok'=>true,'camiones'=>$cams]); exit;
}

echo 'Acción no válida';
