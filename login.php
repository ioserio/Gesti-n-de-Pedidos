<?php
session_start();
require_once __DIR__ . '/conexion.php';

// Crear tabla de usuarios si no existe
$mysqli->query("CREATE TABLE IF NOT EXISTS usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  nombre VARCHAR(100) DEFAULT '',
  rol VARCHAR(32) DEFAULT 'USER',
  activo TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Semilla inicial: si la tabla está vacía, crear admin/admin (cámbialo luego)
$needsSeed = false;
if ($res = $mysqli->query("SELECT COUNT(*) c FROM usuarios")) {
    $row = $res->fetch_assoc();
    if ((int)$row['c'] === 0) { $needsSeed = true; }
    $res->close();
}
if ($needsSeed) {
    $u = 'admin';
    $p = password_hash('admin', PASSWORD_BCRYPT);
    $stmt = $mysqli->prepare('INSERT INTO usuarios (usuario, password_hash, nombre, rol) VALUES (?, ?, \'Administrador\', \'ADMIN\')');
    $stmt->bind_param('ss', $u, $p);
    $stmt->execute();
    $stmt->close();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim($_POST['usuario'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    if ($usuario === '' || $password === '') {
        $error = 'Ingrese usuario y contraseña';
    } else {
        $stmt = $mysqli->prepare('SELECT id, usuario, password_hash, nombre, rol, activo FROM usuarios WHERE usuario = ? LIMIT 1');
        $stmt->bind_param('s', $usuario);
        $stmt->execute();
        $res = $stmt->get_result();
        $user = $res->fetch_assoc();
        $stmt->close();
        if (!$user || (int)$user['activo'] !== 1) {
            $error = 'Usuario o contraseña incorrectos';
        } else {
            $hash = (string)$user['password_hash'];
            $ok = password_verify($password, $hash);
            // Soporte legacy: si se guardó en texto plano, permitir y migrar a bcrypt
            if (!$ok && $hash !== '' && strlen($hash) < 55 && hash_equals($hash, $password)) {
                $ok = true;
                $newHash = password_hash($password, PASSWORD_BCRYPT);
                $stmtU = $mysqli->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?');
                $uid = (int)$user['id'];
                $stmtU->bind_param('si', $newHash, $uid);
                $stmtU->execute();
                $stmtU->close();
            }
            if (!$ok) {
                $error = 'Usuario o contraseña incorrectos';
            } else {
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['usuario'] = (string)$user['usuario'];
                $_SESSION['nombre'] = (string)$user['nombre'];
                $_SESSION['rol'] = (string)$user['rol'];
                $next = isset($_GET['next']) ? (string)$_GET['next'] : 'index.php';
                if ($next === '' || stripos($next, 'login.php') !== false) { $next = 'index.php'; }
                header('Location: ' . $next);
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=480, initial-scale=1">
    <title>Iniciar sesión - RikFlex</title>
    <link rel="stylesheet" href="estilos.css">
    <style>
        body { background: #f2f6ff; }
        .login-wrap { display:flex; align-items:center; justify-content:center; min-height: 100vh; padding: 24px; }
        .login-card { width: 100%; max-width: 420px; background:#fff; border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,.08); padding:28px 26px; }
        .login-title { display:flex; align-items:center; gap:12px; margin-bottom: 18px; }
        .login-title .brand { background: rgba(0,123,255,.12); border:1px solid rgba(0,123,255,.25); color:#0b5ed7; padding:6px 10px; border-radius:10px; font-weight:800; letter-spacing:.3px; }
        .login-title h1 { font-size: 20px; margin: 0; color:#333; }
        .form-row { margin: 12px 0; }
        .form-row label { display:block; font-weight:600; color:#333; margin-bottom:6px; }
        .form-row input { width:100%; padding:10px 12px; border:1px solid #cfd4da; border-radius:6px; font-size:15px; }
        .btn-full { width:100%; display:block; text-align:center; padding:12px; background:#007bff; color:#fff; border:none; border-radius:6px; font-size:16px; cursor:pointer; }
        .btn-full:hover { background:#0056b3; }
        .login-meta { display:flex; justify-content:space-between; align-items:center; margin-top:10px; font-size:13px; color:#6c757d; }
        .err { background:#fdecea; border:1px solid #f5c2c0; color:#842029; padding:10px; border-radius:6px; margin:10px 0; }
        .hint { font-size:12px; color:#6c757d; margin-top:6px; }
    </style>
</head>
<body>
<div class="login-wrap">
    <div class="login-card">
        <div class="login-title">
            <span class="brand">RikFlex</span>
            <h1>Iniciar sesión</h1>
        </div>
        <?php if ($error): ?>
            <div class="err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <form method="post" action="login.php" autocomplete="off">
            <div class="form-row">
                <label for="usuario">Usuario</label>
                <input type="text" id="usuario" name="usuario" required autofocus>
            </div>
            <div class="form-row">
                <label for="password">Contraseña</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" class="btn-full">Entrar</button>
            <div class="hint">Consejo: primera vez usa admin / admin y luego cambia la clave.</div>
        </form>
    </div>
</div>
</body>
</html>
