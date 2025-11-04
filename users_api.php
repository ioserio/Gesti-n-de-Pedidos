<?php
require_once __DIR__ . '/require_login.php';
require_once __DIR__ . '/conexion.php';

header_remove('X-Powered-By');

$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');
$rol = strtoupper((string)($_SESSION['rol'] ?? ''));
$isAdmin = in_array($rol, ['ADMIN','ADMINISTRADOR'], true);
if (!$isAdmin) {
    http_response_code(403);
    echo 'Acceso denegado';
    exit;
}

if ($action === 'list') {
    $res = $mysqli->query("SELECT id, usuario, nombre, rol, activo, created_at FROM usuarios ORDER BY activo DESC, usuario ASC");
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    if ($res) $res->close();
    ob_start();
    echo '<table><thead><tr><th>Usuario</th><th>Nombre</th><th>Rol</th><th>Activo</th><th>Creado</th><th>Acciones</th></tr></thead><tbody>';
    foreach ($rows as $u) {
        $id = (int)$u['id'];
        $usuario = htmlspecialchars($u['usuario'], ENT_QUOTES, 'UTF-8');
        $nombre = htmlspecialchars($u['nombre'], ENT_QUOTES, 'UTF-8');
        $rol = htmlspecialchars($u['rol'], ENT_QUOTES, 'UTF-8');
        $activo = (int)$u['activo'] === 1 ? 'checked' : '';
        $created = htmlspecialchars((string)$u['created_at'], ENT_QUOTES, 'UTF-8');
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
    $password = (string)($_POST['password'] ?? '');
    if ($usuario === '' || $password === '') { echo json_encode(['ok'=>false,'error'=>'REQUIRED']); exit; }
    // Unicidad de usuario
    $stmt = $mysqli->prepare('SELECT id FROM usuarios WHERE usuario = ? LIMIT 1');
    $stmt->bind_param('s', $usuario);
    $stmt->execute(); $res = $stmt->get_result(); $exists = $res->fetch_assoc(); $stmt->close();
    if ($exists) { echo json_encode(['ok'=>false,'error'=>'DUPLICATE']); exit; }
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $mysqli->prepare('INSERT INTO usuarios (usuario, password_hash, nombre, rol, activo) VALUES (?,?,?,?,?)');
    $stmt->bind_param('ssssi', $usuario, $hash, $nombre, $rol, $activo);
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
    if ($id <= 0 || $usuario === '') { echo json_encode(['ok'=>false,'error'=>'PARAMS']); exit; }
    // Unicidad al actualizar
    $stmt = $mysqli->prepare('SELECT id FROM usuarios WHERE usuario = ? AND id <> ? LIMIT 1');
    $stmt->bind_param('si', $usuario, $id);
    $stmt->execute(); $res = $stmt->get_result(); $exists = $res->fetch_assoc(); $stmt->close();
    if ($exists) { echo json_encode(['ok'=>false,'error'=>'DUPLICATE']); exit; }
    $stmt = $mysqli->prepare('UPDATE usuarios SET usuario=?, nombre=?, rol=?, activo=? WHERE id=?');
    $stmt->bind_param('sssii', $usuario, $nombre, $rol, $activo, $id);
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
