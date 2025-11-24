<?php
session_start();
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/conexion.php';
@date_default_timezone_set('America/Lima');

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

// Asegurar columnas de auditoría de sesión si no existen
try {
    $cols = [];
    if ($res = $mysqli->query("SHOW COLUMNS FROM usuarios")) {
        while ($c = $res->fetch_assoc()) { $cols[strtolower($c['Field'])] = true; }
        $res->close();
    }
    $needsLogin = !isset($cols['last_login']);
    $needsSeen = !isset($cols['last_seen']);
    if ($needsLogin || $needsSeen) {
        $alterParts = [];
        if ($needsLogin) $alterParts[] = "ADD COLUMN last_login DATETIME NULL DEFAULT NULL AFTER created_at";
        if ($needsSeen) $alterParts[] = "ADD COLUMN last_seen DATETIME NULL DEFAULT NULL AFTER last_login";
        if (!empty($alterParts)) {
            $sql = 'ALTER TABLE usuarios ' . implode(', ', $alterParts);
            @$mysqli->query($sql);
        }
    }
} catch (Throwable $e) { /* noop */ }

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
                // Registrar último acceso y marca de actividad
                try {
                    // NOW() respeta la zona horaria de la sesión MySQL (seteada en conexion.php)
                    $stmtT = $mysqli->prepare('UPDATE usuarios SET last_login = NOW(), last_seen = NOW() WHERE id = ?');
                    $uid = (int)$user['id'];
                    $stmtT->bind_param('i', $uid);
                    $stmtT->execute();
                    $stmtT->close();
                } catch (Throwable $e) { /* noop */ }
                $next = isset($_GET['next']) ? (string)$_GET['next'] : 'index.php';
                if ($next === '' || stripos($next, 'login.php') !== false) { $next = 'index.php'; }
                header('Location: ' . $next);
                exit;
            }
        }
    }
}

// Detectar ruta válida del logo
$logoFile = 'Logo.png';
$logoRel = 'imagenes/' . $logoFile; // ruta relativa esperada
$logoExists = file_exists(__DIR__ . '/imagenes/' . $logoFile);
// Si estuviera en minúsculas también probar
if (!$logoExists && file_exists(__DIR__ . '/imagenes/' . strtolower($logoFile))) {
    $logoRel = 'imagenes/' . strtolower($logoFile);
    $logoExists = true;
}
// Construir URL con versión para evitar caché
if ($logoExists) {
    $logoUrl = $logoRel . '?v=' . urlencode($assetVersion);
} else {
    $logoUrl = '';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Iniciar sesión - RikFlex</title>
    <link rel="stylesheet" href="estilos.css?v=<?php echo $assetVersion; ?>">
    <style>
        :root { --pad: 24px; }
        html, body { height: 100%; }
        body {
            background: linear-gradient(120deg, #e3eafc 0%, #f7f7f7 55%, #cfd8ff 100%);
            margin:0;
            position:relative;
            min-height:100vh;
        }
        body::after {
            content: '';
            position: fixed;
            z-index: 0;
            inset: 0;
            background: url("data:image/svg+xml;utf8,<svg width='1440' height='900' viewBox='0 0 1440 900' xmlns='http://www.w3.org/2000/svg'><ellipse cx='1200' cy='120' rx='320' ry='120' fill='%23b3c7f7' fill-opacity='0.18'/><ellipse cx='300' cy='800' rx='260' ry='80' fill='%2378aaff' fill-opacity='0.13'/><ellipse cx='900' cy='700' rx='180' ry='60' fill='%23b3c7f7' fill-opacity='0.10'/><ellipse cx='200' cy='200' rx='120' ry='40' fill='%2378aaff' fill-opacity='0.10'/></svg>");
            background-repeat: no-repeat;
            background-size: cover;
            pointer-events: none;
            opacity: 0.6;
        }
        .login-wrap {
            display:flex; align-items:center; justify-content:center;
            min-height: 100vh; padding: var(--pad); box-sizing: border-box;
            position:relative; z-index:1;
        }
        .login-card {
            width: 100%; max-width: 420px;
            background: rgba(255,255,255,0.98);
            border-radius:18px;
            box-shadow:0 10px 32px rgba(120,170,255,0.13), 0 2px 8px rgba(0,0,0,0.08);
            padding:38px 32px 32px 32px;
            position:relative;
        }
        .login-title { text-align:center; margin-bottom: 18px; }
        .login-logo {
            width: 260px;
            max-width: 95%;
            height: auto;
            display: block;
            margin: 0 auto 18px;
            filter: drop-shadow(0 2px 6px rgba(0,0,0,.15));
        }
        .login-title h1 { font-size: 24px; margin: 0 0 6px 0; color:#333; font-weight:700; letter-spacing:.3px; }
        .login-welcome {
            text-align:center; color:#555; font-size:15px; margin-bottom:10px;
        }
        .form-row { margin: 14px 0; }
        .form-row label { display:block; font-weight:600; color:#333; margin-bottom:6px; }
        .form-row input {
            width:100%; padding:14px; border:1px solid #cfd4da; border-radius:10px; font-size:16px; box-sizing:border-box;
            background:#f7faff; transition:border .2s;
        }
        .form-row input:focus { border-color:#007bff; outline:none; }
        .btn-full {
            width:100%; display:block; text-align:center; padding:14px; background:linear-gradient(90deg,#007bff 70%,#0056b3 100%);
            color:#fff; border:none; border-radius:10px; font-size:17px; cursor:pointer; font-weight:600; letter-spacing:.3px;
            box-shadow:0 4px 16px rgba(0,123,255,0.18); transition:box-shadow .25s, transform .25s, background .25s;
        }
        .btn-full:hover { background:#0056b3; box-shadow:0 6px 22px rgba(0,123,255,0.25); transform:translateY(-2px); }
        .btn-full:active { transform:translateY(0); box-shadow:0 3px 12px rgba(0,123,255,0.18); }
        .err { background:#fdecea; border:1px solid #f5c2c0; color:#842029; padding:10px; border-radius:8px; margin:10px 0; text-align:center; }
        .hint { font-size:12px; color:#6c757d; margin-top:6px; text-align:center; }
        /* Responsivo: móviles estrechos */
        @media (max-width: 480px) {
            :root { --pad: 10px; }
            .login-card { padding:20px 12px; border-radius:14px; }
            .login-logo {
                width: 200px;
                max-width: 98%;
                margin-bottom: 14px;
            }
            .login-title h1 { font-size: 20px; }
            .form-row { margin: 10px 0; }
            .btn-full { padding: 12px; font-size: 16px; }
        }
        @media (max-width: 360px) {
            .login-card { padding:12px 2px; }
            .login-title h1 { font-size: 16px; }
        }
    </style>
</head>
<body>
<div class="login-wrap">
    <div class="login-card">
        <div class="login-title">
            <img src="<?php echo $logoUrl ?: 'Logo_sinfondo2.png?v=' . urlencode($assetVersion); ?>" alt="Logo" class="login-logo">
            <h1>Iniciar sesión</h1>
        </div>
        <div class="login-welcome">Bienvenido, ingresa tus credenciales para acceder al sistema.</div>
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
        </form>
        <div class="hint">¿Olvidaste tu contraseña? Contacta al administrador.</div>
    </div>
</div>
</body>
</html>
