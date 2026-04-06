<?php
require_once __DIR__ . '/require_login.php';
require_once __DIR__ . '/conexion.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');

if ($action === 'list') {
    $res = $mysqli->query("SELECT dia_semana, hora_ingreso FROM config_horarios_ingreso ORDER BY dia_semana ASC");
    $horarios = [];
    while ($row = $res->fetch_assoc()) {
        $horarios[] = $row;
    }
    echo json_encode(['ok' => true, 'horarios' => $horarios]);
    exit;
}

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $dia = (int)($_POST['dia_semana'] ?? 0);
    $hora = $_POST['hora_ingreso'] ?? '';

    if ($dia < 1 || $dia > 7 || empty($hora)) {
        echo json_encode(['ok' => false, 'error' => 'Datos inválidos']);
        exit;
    }

    // Asegurar formato HH:MM:SS
    if (strlen($hora) === 5) {
        $hora .= ':00';
    }

    $stmt = $mysqli->prepare("INSERT INTO config_horarios_ingreso (dia_semana, hora_ingreso) VALUES (?, ?) 
                              ON DUPLICATE KEY UPDATE hora_ingreso = VALUES(hora_ingreso)");
    $stmt->bind_param('is', $dia, $hora);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode(['ok' => $ok]);
    exit;
}