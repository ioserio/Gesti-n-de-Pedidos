<?php
require_once __DIR__ . '/require_login.php';
require_once __DIR__ . '/conexion.php';

header_remove('X-Powered-By');

// Definir módulos disponibles
$MODULES = [
  'importar'    => 'Importar',
  'consultar'   => 'Consulta por vd',
  'resumen'     => 'Resumen de Pedidos',
  'cobranzas'   => 'Gestión de Cobranzas',
  'devoluciones'=> 'Gestión de Devoluciones',
  'recojos'     => 'Consulta de Recojos',
  'admin'       => 'Administrador de Cuotas',
    'rutas'       => 'Rutas',
    'usuarios'    => 'Usuarios',
  'permisos'    => 'Permisos',
];

$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');
$uid = $_SESSION['user_id'] ?? 0;
$rol = strtoupper((string)($_SESSION['rol'] ?? ''));
$isAdmin = in_array($rol, ['ADMIN','ADMINISTRADOR'], true);

// Crear tabla de permisos si no existe
$mysqli->query("CREATE TABLE IF NOT EXISTS usuarios_permisos (
  user_id INT NOT NULL,
  modulo VARCHAR(32) NOT NULL,
  permitido TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, modulo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

function defaultAllowed($user, $mod){
    // Por defecto, los ADMIN tienen todo permitido
    $r = strtoupper((string)($user['rol'] ?? ''));
    return in_array($r, ['ADMIN','ADMINISTRADOR'], true);
}

if ($action === 'my') {
    header('Content-Type: application/json');
    $id = (int)($uid);
    if ($id <= 0) { echo json_encode(['ok'=>false,'error'=>'NO_SESSION']); exit; }
    // Cargar permisos explícitos de este usuario
    $stmt = $mysqli->prepare('SELECT modulo, permitido FROM usuarios_permisos WHERE user_id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $explicit = [];
    while ($r = $res->fetch_assoc()) { $explicit[$r['modulo']] = (int)$r['permitido'] === 1; }
    $stmt->close();
    // Obtener rol del usuario
    $u = ['rol' => $_SESSION['rol'] ?? ''];
    $perms = [];
    global $MODULES;
    foreach ($MODULES as $key => $label) {
        // Regla general: si hay override explícito en la tabla, lo respetamos;
        // si no, usamos el default por rol (para ADMIN/ADMINISTRADOR => true por defecto).
        $perms[$key] = array_key_exists($key, $explicit) ? $explicit[$key] : defaultAllowed($u, $key);
    }
    echo json_encode(['ok'=>true, 'permisos'=>$perms]);
    exit;
}

// Desde aquí, solo ADMIN puede listar/editar
if (!$isAdmin) {
    http_response_code(403);
    echo 'Acceso denegado';
    exit;
}

if ($action === 'toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $userId = (int)($_POST['user_id'] ?? 0);
    $mod = (string)($_POST['modulo'] ?? '');
    $val = (int)(($_POST['value'] ?? 0) ? 1 : 0);
    if ($userId <= 0 || $mod === '' || !isset($MODULES[$mod])) {
        echo json_encode(['ok'=>false,'error'=>'PARAMS']); exit;
    }
    // Evitar que el admin actual se quite a sí mismo el permiso de Permisos
    if ($userId === $uid && $mod === 'permisos' && $val === 0) {
        echo json_encode(['ok'=>false,'error'=>'NO_SELF_LOCK']); exit;
    }
    // Upsert
    $stmt = $mysqli->prepare('REPLACE INTO usuarios_permisos (user_id, modulo, permitido) VALUES (?,?,?)');
    $stmt->bind_param('isi', $userId, $mod, $val);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['ok'=>$ok?true:false]);
    exit;
}

if ($action === 'bulk' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId <= 0) { echo json_encode(['ok'=>false,'error'=>'PARAMS']); exit; }
    // Leer mapa de mods desde JSON o desde pares mod_* del form
    $mods = [];
    if (isset($_POST['mods'])) {
        $decoded = json_decode((string)$_POST['mods'], true);
        if (is_array($decoded)) { $mods = $decoded; }
    } else {
        foreach ($MODULES as $k => $label) {
            $mods[$k] = isset($_POST['mod_'.$k]) ? (int)($_POST['mod_'.$k] ? 1 : 0) : null;
        }
    }
    // Filtrar claves inválidas y normalizar 0/1
    $clean = [];
    foreach ($mods as $k => $v) {
        if (!isset($MODULES[$k])) continue;
        $clean[$k] = ((int)$v) === 1 ? 1 : 0;
    }
    if (!$clean) { echo json_encode(['ok'=>false,'error'=>'EMPTY']); exit; }
    // Proteger: no permitir que el usuario actual se quite "permisos"
    if ($userId === $uid && array_key_exists('permisos', $clean) && (int)$clean['permisos'] === 0) {
        // Forzar a 1
        $clean['permisos'] = 1;
    }
    // Guardar en lote (upsert por cada módulo)
    $stmt = $mysqli->prepare('REPLACE INTO usuarios_permisos (user_id, modulo, permitido) VALUES (?,?,?)');
    if (!$stmt) { echo json_encode(['ok'=>false,'error'=>'DB']); exit; }
    $ok = true; $count = 0;
    foreach ($clean as $mod => $val) {
        $stmt->bind_param('isi', $userId, $mod, $val);
        if (!$stmt->execute()) { $ok = false; break; }
        $count++;
    }
    $stmt->close();
    echo json_encode(['ok'=>$ok?true:false, 'updated'=>$count]);
    exit;
}

// LISTAR TABLA
if ($action === 'list') {
    // Usuarios activos primero, luego inactivos
    $res = $mysqli->query("SELECT id, usuario, nombre, rol, activo FROM usuarios ORDER BY activo DESC, usuario ASC");
    $users = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    if ($res) $res->close();
    // Permisos explícitos
    $res2 = $mysqli->query('SELECT user_id, modulo, permitido FROM usuarios_permisos');
    $exp = [];
    while ($row = $res2->fetch_assoc()) {
        $exp[(int)$row['user_id']][$row['modulo']] = (int)$row['permitido'] === 1;
    }
    if ($res2) $res2->close();
    // Render HTML
    ob_start();
    echo '<table><thead><tr><th>Usuario</th><th>Nombre</th>';
    foreach ($MODULES as $k=>$label) { echo '<th>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</th>'; }
    echo '<th>Acciones</th></tr></thead><tbody>';
    foreach ($users as $u) {
        $isActive = (int)$u['activo'] === 1;
        echo '<tr>';
        echo '<td>' . htmlspecialchars($u['usuario'], ENT_QUOTES, 'UTF-8') . ($isActive?'':' <small style="color:#888">(inactivo)</small>') . '</td>';
        echo '<td>' . htmlspecialchars($u['nombre'], ENT_QUOTES, 'UTF-8') . '</td>';
        foreach ($MODULES as $k=>$label) {
            // Mostrar permitido según override o default por rol
            $allowed = isset($exp[(int)$u['id']][$k]) ? $exp[(int)$u['id']][$k] : defaultAllowed($u, $k);
            $disabled = '';
            // Evitar que el usuario actual se quite acceso a permisos
            if ((int)$u['id'] === $uid && $k === 'permisos') { $disabled = ' disabled'; }
            echo '<td style="text-align:center">'
                . '<input type="checkbox" class="perm-toggle" data-uid="' . (int)$u['id'] . '" data-mod="' . htmlspecialchars($k, ENT_QUOTES, 'UTF-8') . '"' . ($allowed?' checked':'') . $disabled . '>'
                . '</td>';
        }
        echo '<td style="text-align:center">'
            . '<button type="button" class="perm-bulk-save" data-uid="' . (int)$u['id'] . '">Guardar cambios</button>'
            . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '<p style="margin-top:10px;color:#666;">Los cambios se guardan automáticamente al marcar o desmarcar. Consejo: los usuarios con rol ADMIN tienen todos los módulos permitidos por defecto. Los cambios aquí sobrescriben ese comportamiento para el usuario específico.</p>';
    $html = ob_get_clean();
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

echo 'Acción no válida';
