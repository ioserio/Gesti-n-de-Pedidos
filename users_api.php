<?php
require_once __DIR__ . '/require_login.php';
require_once __DIR__ . '/conexion.php';

header_remove('X-Powered-By');

function get_table_columns($mysqli, string $table): array {
    $cols = [];
    if ($res = $mysqli->query("SHOW COLUMNS FROM `{$table}`")) {
        while ($c = $res->fetch_assoc()) {
            $cols[strtolower((string)$c['Field'])] = true;
        }
        $res->close();
    }
    return $cols;
}

function table_exists($mysqli, string $table): bool {
    $safeTable = $mysqli->real_escape_string($table);
    $res = $mysqli->query("SHOW TABLES LIKE '{$safeTable}'");
    if (!$res) {
        return false;
    }
    $exists = $res->num_rows > 0;
    $res->close();
    return $exists;
}

function ensure_users_supervisor_column($mysqli): bool {
    try {
        $cols = get_table_columns($mysqli, 'usuarios');
        if (isset($cols['supervisor_id'])) {
            return true;
        }
        if (!table_exists($mysqli, 'supervisores_ventas')) {
            return false;
        }
        $afterColumn = isset($cols['last_seen']) ? 'last_seen' : (isset($cols['created_at']) ? 'created_at' : null);
        $alterSql = 'ALTER TABLE usuarios ADD COLUMN supervisor_id INT NULL DEFAULT NULL';
        if ($afterColumn !== null) {
            $alterSql .= ' AFTER ' . $afterColumn;
        }
        return (bool)@$mysqli->query($alterSql);
    } catch (Throwable $e) {
        return false;
    }
}

function get_supervisor_catalog($mysqli): array {
    $options = [];
    if (table_exists($mysqli, 'supervisores_ventas')) {
        $res = $mysqli->query('SELECT id, mesa, nombre FROM supervisores_ventas ORDER BY mesa ASC, nombre ASC');
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $mesa = isset($row['mesa']) ? trim((string)$row['mesa']) : '';
                $nombre = trim((string)$row['nombre']);
                $label = $nombre;
                if ($mesa !== '') {
                    $label = $mesa . ' - ' . $nombre;
                }
                $options[] = [
                    'value' => (int)$row['id'],
                    'label' => $label,
                ];
            }
            $res->close();
        }
    }
    return $options;
}

function render_supervisor_select(string $className, array $catalog, ?int $selected = null, bool $disabled = false): string {
    $attrs = 'class="' . htmlspecialchars($className, ENT_QUOTES, 'UTF-8') . '"';
    if ($disabled) {
        $attrs .= ' disabled';
    }
    $html = '<select ' . $attrs . '>';
    $html .= '<option value="">-- Sin supervisor --</option>';
    foreach ($catalog as $opt) {
        $value = (int)$opt['value'];
        $label = htmlspecialchars((string)$opt['label'], ENT_QUOTES, 'UTF-8');
        $sel = ($selected !== null && $selected === $value) ? ' selected' : '';
        $html .= '<option value="' . $value . '"' . $sel . '>' . $label . '</option>';
    }
    $html .= '</select>';
    return $html;
}

$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');
$rol = strtoupper((string)($_SESSION['rol'] ?? ''));
$isAdmin = in_array($rol, ['ADMIN','ADMINISTRADOR'], true);
if (!$isAdmin) {
    http_response_code(403);
    echo 'Acceso denegado';
    exit;
}

// Asegurar columnas para auditoría de sesión
function ensure_session_columns($mysqli) {
    try {
        $cols = get_table_columns($mysqli, 'usuarios');
        $needsLogin = !isset($cols['last_login']);
        $needsSeen = !isset($cols['last_seen']);
        if ($needsLogin || $needsSeen) {
            $alter = [];
            if ($needsLogin) $alter[] = "ADD COLUMN last_login DATETIME NULL DEFAULT NULL";
            if ($needsSeen) $alter[] = "ADD COLUMN last_seen DATETIME NULL DEFAULT NULL";
            if ($alter) {
                @$mysqli->query('ALTER TABLE usuarios '.implode(', ', $alter));
            }
        }
    } catch (Throwable $e) { /* noop */ }
}

if ($action === 'supervisor_options') {
    header('Content-Type: application/json');
    $hasSupervisorId = ensure_users_supervisor_column($mysqli);
    echo json_encode([
        'ok' => true,
        'enabled' => $hasSupervisorId,
        'options' => get_supervisor_catalog($mysqli),
    ]);
    exit;
}

if ($action === 'heartbeat') {
    header('Content-Type: application/json');
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid > 0) {
        ensure_session_columns($mysqli);
        $stmt = $mysqli->prepare('UPDATE usuarios SET last_seen = NOW() WHERE id = ?');
        $stmt->bind_param('i', $uid);
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode(['ok' => $ok ? true : false]);
        exit;
    }
    echo json_encode(['ok' => false]);
    exit;
}

if ($action === 'sessions') {
    ensure_session_columns($mysqli);
    $mins = isset($_GET['mins']) ? max(1, (int)$_GET['mins']) : 5;
    // Convertir a hora local (America/Lima) si la sesión no tiene TZ correcta (NOW() ya ajustado en conexion.php). Por si acaso, se puede forzar.
    @$mysqli->query("SET time_zone='-05:00'");
    $res = $mysqli->query("SELECT id, usuario, nombre, rol, activo, created_at, last_login, last_seen, TIMESTAMPDIFF(MINUTE, last_seen, NOW()) AS mins_ago FROM usuarios ORDER BY activo DESC, (last_seen IS NULL) ASC, last_seen DESC, usuario ASC");
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    if ($res) $res->close();
    ob_start();
    echo '<div style="display:flex; align-items:center; gap:10px; margin-bottom:8px;">'
        .'<strong>Usuarios en línea</strong>'
        .'<span style="color:#6c757d;">(considerados "en línea" si actividad en &le; '.$mins.' min)</span>'
        .'<button type="button" id="btn-refresh-sessions" style="margin-left:auto;">Actualizar</button>'
        .'</div>';
    echo '<table><thead><tr>'
        .'<th>Usuario</th><th>Nombre</th><th>Rol</th><th>Activo</th><th>En línea</th><th>Último acceso</th><th>Último movimiento</th>'
        .'</tr></thead><tbody>';
    foreach ($rows as $u) {
        $online = false;
        if (!is_null($u['mins_ago'])) {
            $online = ((int)$u['mins_ago']) <= $mins;
        }
        $dot = $online ? '<span title="En línea" style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#28a745;vertical-align:middle;margin-right:6px;"></span>'
                       : '<span title="Desconectado" style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#adb5bd;vertical-align:middle;margin-right:6px;"></span>';
        $usuario = htmlspecialchars((string)$u['usuario'], ENT_QUOTES, 'UTF-8');
        $nombre = htmlspecialchars((string)$u['nombre'], ENT_QUOTES, 'UTF-8');
        $rolu = htmlspecialchars((string)$u['rol'], ENT_QUOTES, 'UTF-8');
        $activo = ((int)$u['activo'] === 1) ? 'Sí' : 'No';
        $ll = $u['last_login'] ? htmlspecialchars((string)$u['last_login'], ENT_QUOTES, 'UTF-8') : '-';
        $ls = $u['last_seen'] ? htmlspecialchars((string)$u['last_seen'], ENT_QUOTES, 'UTF-8') : '-';
        echo '<tr>'
            .'<td>'.$usuario.'</td>'
            .'<td>'.$nombre.'</td>'
            .'<td>'.$rolu.'</td>'
            .'<td style="text-align:center;">'.$activo.'</td>'
            .'<td>'.$dot.($online ? 'Sí' : 'No').'</td>'
            .'<td>'.$ll.'</td>'
            .'<td>'.$ls.'</td>'
            .'</tr>';
    }
    echo '</tbody></table>';
    $html = ob_get_clean();
    header('Content-Type: text/html; charset=utf-8');
    echo $html; exit;
}

if ($action === 'list') {
    $hasSupervisorId = ensure_users_supervisor_column($mysqli);
    $catalog = $hasSupervisorId ? get_supervisor_catalog($mysqli) : [];
    $sql = $hasSupervisorId
        ? "SELECT id, usuario, nombre, rol, activo, created_at, supervisor_id FROM usuarios ORDER BY activo DESC, usuario ASC"
        : "SELECT id, usuario, nombre, rol, activo, created_at FROM usuarios ORDER BY activo DESC, usuario ASC";
    $res = $mysqli->query($sql);
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    if ($res) $res->close();
    ob_start();
    echo '<table><thead><tr><th>Usuario</th><th>Nombre</th><th>Rol</th>' . ($hasSupervisorId ? '<th>Supervisor</th>' : '') . '<th>Activo</th><th>Creado</th><th>Acciones</th></tr></thead><tbody>';
    foreach ($rows as $u) {
        $id = (int)$u['id'];
        $usuario = htmlspecialchars($u['usuario'], ENT_QUOTES, 'UTF-8');
        $nombre = htmlspecialchars($u['nombre'], ENT_QUOTES, 'UTF-8');
        $rol = htmlspecialchars($u['rol'], ENT_QUOTES, 'UTF-8');
        $activo = (int)$u['activo'] === 1 ? 'checked' : '';
        $created = htmlspecialchars((string)$u['created_at'], ENT_QUOTES, 'UTF-8');
        $supervisorId = ($hasSupervisorId && isset($u['supervisor_id']) && $u['supervisor_id'] !== null) ? (int)$u['supervisor_id'] : null;
        echo '<tr data-id="'.$id.'">'
            .'<td><input type="text" class="u-usuario" value="'.$usuario.'" style="width:140px;"></td>'
            .'<td><input type="text" class="u-nombre" value="'.$nombre.'" style="min-width:200px;"></td>'
                .'<td><select class="u-rol">'
                    .'<option value="USER"'.($rol==='USER'?' selected':'').'>USER</option>'
                    .'<option value="ADMIN"'.($rol==='ADMIN'?' selected':'').'>ADMIN</option>'
                    .'<option value="ADMINISTRADOR"'.($rol==='ADMINISTRADOR'?' selected':'').'>ADMINISTRADOR</option>'
                    .'<option value="CUENTACORRIENTE"'.($rol==='CUENTACORRIENTE'?' selected':'').'>CUENTACORRIENTE</option>'
                    .'<option value="SUPERVISOR"'.($rol==='SUPERVISOR'?' selected':'').'>SUPERVISOR</option>'
                    .'<option value="FACTURADOR"'.($rol==='FACTURADOR'?' selected':'').'>FACTURADOR</option>'
                .'</select></td>'
            .($hasSupervisorId ? '<td>' . render_supervisor_select('u-supervisor', $catalog, $supervisorId, empty($catalog)) . '</td>' : '')
            .'<td style="text-align:center"><input type="checkbox" class="u-activo" '.$activo.'></td>'
            .'<td>'.$created.'</td>'
            .'<td style="white-space:nowrap">'
                .'<button type="button" class="user-save">Guardar</button> '
                .'<button type="button" class="user-reset" style="background:#6c757d">Reset clave</button>'
            .'</td>'
            .'</tr>';
    }
    echo '</tbody></table>';
    $html = ob_get_clean();
    header('Content-Type: text/html; charset=utf-8');
    echo $html; exit;
}

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $usuario = trim((string)($_POST['usuario'] ?? ''));
    $nombre = trim((string)($_POST['nombre'] ?? ''));
    $rol = trim((string)($_POST['rol'] ?? 'USER'));
    $activo = isset($_POST['activo']) && (($_POST['activo'] === '1') || ($_POST['activo'] === 'on')) ? 1 : 0;
    $hasSupervisorId = ensure_users_supervisor_column($mysqli);
    $supervisorId = ($hasSupervisorId && isset($_POST['supervisor_id']) && $_POST['supervisor_id'] !== '') ? (int)$_POST['supervisor_id'] : null;
    $password = (string)($_POST['password'] ?? '');
    if ($usuario === '' || $password === '') { echo json_encode(['ok'=>false,'error'=>'REQUIRED']); exit; }
    // Unicidad de usuario
    $stmt = $mysqli->prepare('SELECT id FROM usuarios WHERE usuario = ? LIMIT 1');
    $stmt->bind_param('s', $usuario);
    $stmt->execute(); $res = $stmt->get_result(); $exists = $res->fetch_assoc(); $stmt->close();
    if ($exists) { echo json_encode(['ok'=>false,'error'=>'DUPLICATE']); exit; }
    $hash = password_hash($password, PASSWORD_BCRYPT);
    if ($hasSupervisorId) {
        $stmt = $mysqli->prepare('INSERT INTO usuarios (usuario, password_hash, nombre, rol, activo, supervisor_id) VALUES (?,?,?,?,?,?)');
        $stmt->bind_param('ssssii', $usuario, $hash, $nombre, $rol, $activo, $supervisorId);
    } else {
        $stmt = $mysqli->prepare('INSERT INTO usuarios (usuario, password_hash, nombre, rol, activo) VALUES (?,?,?,?,?)');
        $stmt->bind_param('ssssi', $usuario, $hash, $nombre, $rol, $activo);
    }
    $ok = $stmt->execute(); $newId = $ok ? $stmt->insert_id : 0; $stmt->close();
    echo json_encode(['ok'=>$ok?true:false, 'id'=>$newId]); exit;
}

if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    $usuario = trim((string)($_POST['usuario'] ?? ''));
    $nombre = trim((string)($_POST['nombre'] ?? ''));
    $rol = trim((string)($_POST['rol'] ?? 'USER'));
    $activo = isset($_POST['activo']) && (($_POST['activo'] === '1') || ($_POST['activo'] === 'on')) ? 1 : 0;
    $hasSupervisorId = ensure_users_supervisor_column($mysqli);
    $supervisorId = ($hasSupervisorId && isset($_POST['supervisor_id']) && $_POST['supervisor_id'] !== '') ? (int)$_POST['supervisor_id'] : null;
    if ($id <= 0 || $usuario === '') { echo json_encode(['ok'=>false,'error'=>'PARAMS']); exit; }
    // Unicidad al actualizar
    $stmt = $mysqli->prepare('SELECT id FROM usuarios WHERE usuario = ? AND id <> ? LIMIT 1');
    $stmt->bind_param('si', $usuario, $id);
    $stmt->execute(); $res = $stmt->get_result(); $exists = $res->fetch_assoc(); $stmt->close();
    if ($exists) { echo json_encode(['ok'=>false,'error'=>'DUPLICATE']); exit; }
    if ($hasSupervisorId) {
        $stmt = $mysqli->prepare('UPDATE usuarios SET usuario=?, nombre=?, rol=?, activo=?, supervisor_id=? WHERE id=?');
        $stmt->bind_param('sssiii', $usuario, $nombre, $rol, $activo, $supervisorId, $id);
    } else {
        $stmt = $mysqli->prepare('UPDATE usuarios SET usuario=?, nombre=?, rol=?, activo=? WHERE id=?');
        $stmt->bind_param('sssii', $usuario, $nombre, $rol, $activo, $id);
    }
    $ok = $stmt->execute(); $stmt->close();
    echo json_encode(['ok'=>$ok?true:false]); exit;
}

if ($action === 'reset_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    $password = (string)($_POST['password'] ?? '');
    if ($id <= 0 || $password === '') { echo json_encode(['ok'=>false,'error'=>'PARAMS']); exit; }
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $mysqli->prepare('UPDATE usuarios SET password_hash=? WHERE id=?');
    $stmt->bind_param('si', $hash, $id);
    $ok = $stmt->execute(); $stmt->close();
    echo json_encode(['ok'=>$ok?true:false]); exit;
}

echo 'Acción no válida';
