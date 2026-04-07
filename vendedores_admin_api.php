<?php
require_once __DIR__ . '/require_login.php';
require_once __DIR__ . '/conexion.php';

header_remove('X-Powered-By');

$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');
$rol = strtoupper((string)($_SESSION['rol'] ?? ''));
$isAdmin = in_array($rol, ['ADMIN', 'ADMINISTRADOR'], true);
if (!$isAdmin) {
    http_response_code(403);
    echo 'Acceso denegado';
    exit;
}

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function normalize_vendor_code(string $code): string {
    $digits = preg_replace('/\D+/', '', trim($code));
    if ($digits === null) {
        $digits = '';
    }
    if ($digits === '') {
        return '';
    }
    if (strlen($digits) > 3) {
        $digits = substr($digits, -3);
    }
    return str_pad($digits, 3, '0', STR_PAD_LEFT);
}

function get_table_columns($mysqli, string $table): array {
    $cols = [];
    if ($res = $mysqli->query("SHOW COLUMNS FROM `{$table}`")) {
        while ($row = $res->fetch_assoc()) {
            $cols[strtolower((string)$row['Field'])] = true;
        }
        $res->close();
    }
    return $cols;
}

function has_vendor_supervisor_column($mysqli): bool {
    $cols = get_table_columns($mysqli, 'vendedores');
    return isset($cols['id_supervisor']);
}

function get_supervisores_catalog($mysqli): array {
    $items = [];
    $res = $mysqli->query("SELECT id, mesa, nombre FROM supervisores_ventas ORDER BY mesa ASC, nombre ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $mesa = trim((string)($row['mesa'] ?? ''));
            $nombre = trim((string)($row['nombre'] ?? ''));
            $label = $nombre;
            if ($mesa !== '') {
                $label = $mesa . ' - ' . $nombre;
            }
            $items[] = [
                'id' => (int)$row['id'],
                'label' => $label,
            ];
        }
        $res->close();
    }
    return $items;
}

function render_supervisor_select(string $className, array $catalog, ?int $selected = null): string {
    $html = '<select class="' . h($className) . '" style="min-width:190px;">';
    $html .= '<option value="">-- Sin supervisor --</option>';
    foreach ($catalog as $item) {
        $id = (int)$item['id'];
        $sel = ($selected !== null && $selected === $id) ? ' selected' : '';
        $html .= '<option value="' . $id . '"' . $sel . '>' . h($item['label']) . '</option>';
    }
    $html .= '</select>';
    return $html;
}

if ($action === 'supervisor_options') {
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'enabled' => has_vendor_supervisor_column($mysqli),
        'options' => get_supervisores_catalog($mysqli),
    ]);
    exit;
}

if ($action === 'list') {
    $hasSupervisorId = has_vendor_supervisor_column($mysqli);
    $catalog = $hasSupervisorId ? get_supervisores_catalog($mysqli) : [];
    $sql = $hasSupervisorId
        ? "SELECT codigo, nombre, id_supervisor FROM vendedores ORDER BY CAST(codigo AS UNSIGNED) ASC, codigo ASC"
        : "SELECT codigo, nombre FROM vendedores ORDER BY CAST(codigo AS UNSIGNED) ASC, codigo ASC";
    $res = $mysqli->query($sql);
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    if ($res) {
        $res->close();
    }
    ob_start();
    echo '<table><thead><tr><th>Código</th><th>Nombre</th>' . ($hasSupervisorId ? '<th>Supervisor</th>' : '') . '<th>Acciones</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $codigo = (string)$row['codigo'];
        $nombre = (string)$row['nombre'];
        $supervisorId = ($hasSupervisorId && isset($row['id_supervisor']) && $row['id_supervisor'] !== null) ? (int)$row['id_supervisor'] : null;
        echo '<tr data-codigo="' . h($codigo) . '">'
            . '<td><input type="text" class="v-codigo" value="' . h($codigo) . '" maxlength="3" style="width:90px;"></td>'
            . '<td><input type="text" class="v-nombre" value="' . h($nombre) . '" style="min-width:260px;"></td>'
            . ($hasSupervisorId ? '<td>' . render_supervisor_select('v-supervisor', $catalog, $supervisorId) . '</td>' : '')
            . '<td style="white-space:nowrap">'
            . '<button type="button" class="vendor-save">Guardar</button> '
            . '<button type="button" class="vendor-delete" style="background:#dc3545;">Eliminar</button>'
            . '</td>'
            . '</tr>';
    }
    if (!$rows) {
        echo '<tr><td colspan="4">No hay vendedores registrados.</td></tr>';
    }
    echo '</tbody></table>';
    header('Content-Type: text/html; charset=utf-8');
    echo ob_get_clean();
    exit;
}

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $codigo = normalize_vendor_code((string)($_POST['codigo'] ?? ''));
    $nombre = trim((string)($_POST['nombre'] ?? ''));
    $hasSupervisorId = has_vendor_supervisor_column($mysqli);
    $supervisorId = ($hasSupervisorId && isset($_POST['id_supervisor']) && $_POST['id_supervisor'] !== '') ? (int)$_POST['id_supervisor'] : null;
    if ($codigo === '' || $nombre === '') {
        echo json_encode(['ok' => false, 'error' => 'REQUIRED']);
        exit;
    }
    $stmt = $mysqli->prepare('SELECT codigo FROM vendedores WHERE codigo = ? LIMIT 1');
    $stmt->bind_param('s', $codigo);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($exists) {
        echo json_encode(['ok' => false, 'error' => 'DUPLICATE']);
        exit;
    }
    if ($hasSupervisorId) {
        $stmt = $mysqli->prepare('INSERT INTO vendedores (codigo, nombre, id_supervisor) VALUES (?, ?, ?)');
        $stmt->bind_param('ssi', $codigo, $nombre, $supervisorId);
    } else {
        $stmt = $mysqli->prepare('INSERT INTO vendedores (codigo, nombre) VALUES (?, ?)');
        $stmt->bind_param('ss', $codigo, $nombre);
    }
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['ok' => $ok ? true : false]);
    exit;
}

if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $codigoOriginal = normalize_vendor_code((string)($_POST['codigo_original'] ?? ''));
    $codigoNuevo = normalize_vendor_code((string)($_POST['codigo'] ?? ''));
    $nombre = trim((string)($_POST['nombre'] ?? ''));
    $hasSupervisorId = has_vendor_supervisor_column($mysqli);
    $supervisorId = ($hasSupervisorId && isset($_POST['id_supervisor']) && $_POST['id_supervisor'] !== '') ? (int)$_POST['id_supervisor'] : null;
    if ($codigoOriginal === '' || $codigoNuevo === '' || $nombre === '') {
        echo json_encode(['ok' => false, 'error' => 'PARAMS']);
        exit;
    }
    if ($codigoOriginal !== $codigoNuevo) {
        $stmt = $mysqli->prepare('SELECT codigo FROM vendedores WHERE codigo = ? LIMIT 1');
        $stmt->bind_param('s', $codigoNuevo);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($exists) {
            echo json_encode(['ok' => false, 'error' => 'DUPLICATE']);
            exit;
        }
    }
    if ($hasSupervisorId) {
        $stmt = $mysqli->prepare('UPDATE vendedores SET codigo = ?, nombre = ?, id_supervisor = ? WHERE codigo = ?');
        $stmt->bind_param('ssis', $codigoNuevo, $nombre, $supervisorId, $codigoOriginal);
    } else {
        $stmt = $mysqli->prepare('UPDATE vendedores SET codigo = ?, nombre = ? WHERE codigo = ?');
        $stmt->bind_param('sss', $codigoNuevo, $nombre, $codigoOriginal);
    }
    $ok = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    echo json_encode(['ok' => ($ok && $affected >= 0) ? true : false, 'codigo' => $codigoNuevo]);
    exit;
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $codigo = normalize_vendor_code((string)($_POST['codigo'] ?? ''));
    if ($codigo === '') {
        echo json_encode(['ok' => false, 'error' => 'PARAMS']);
        exit;
    }
    $stmt = $mysqli->prepare('DELETE FROM vendedores WHERE codigo = ?');
    $stmt->bind_param('s', $codigo);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['ok' => $ok ? true : false]);
    exit;
}

http_response_code(400);
echo 'Acción no válida';
