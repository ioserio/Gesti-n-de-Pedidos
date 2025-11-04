<?php
session_start();
header('Content-Type: application/json');
$ok = isset($_SESSION['user_id']);
echo json_encode([
  'authenticated' => $ok,
  'id' => $ok ? (int)($_SESSION['user_id'] ?? 0) : 0,
  'usuario' => $ok ? ($_SESSION['usuario'] ?? '') : '',
  'nombre' => $ok ? ($_SESSION['nombre'] ?? '') : '',
  'rol' => $ok ? ($_SESSION['rol'] ?? '') : ''
]);
