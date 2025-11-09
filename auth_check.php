<?php
session_start();
header('Content-Type: application/json');
$ok = isset($_SESSION['user_id']);
$id = $ok ? (int)($_SESSION['user_id'] ?? 0) : 0;
$usuario = $ok ? ($_SESSION['usuario'] ?? '') : '';
$nombre = $ok ? ($_SESSION['nombre'] ?? '') : '';
$rol = $ok ? ($_SESSION['rol'] ?? '') : '';

// Actualizar marca de actividad (heartbeat) cada vez que el cliente consulta auth_check (máx una vez cada 30s)
if ($ok) {
    try {
        // Conexión liviana sin incluir todo (evitar require si se desea minimal). Reutilizamos conexion.php.
        require_once __DIR__ . '/conexion.php';
        // Control de throttling: opcionalmente se podría usar una variable de sesión.
        $lastPing = $_SESSION['__last_seen_update'] ?? 0;
        $now = time();
        if ($now - (int)$lastPing >= 30) {
            $stmt = $mysqli->prepare('UPDATE usuarios SET last_seen = NOW() WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['__last_seen_update'] = $now;
        }
    } catch (Throwable $e) { /* noop */ }
}

echo json_encode([
  'authenticated' => $ok,
  'id' => $id,
  'usuario' => $usuario,
  'nombre' => $nombre,
  'rol' => $rol
]);
