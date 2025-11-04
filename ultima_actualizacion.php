<?php
require_once __DIR__ . '/require_login.php';
require_once __DIR__ . '/conexion.php';
header('Content-Type: text/plain; charset=UTF-8');

// Obtener la última fecha y, dentro de esa fecha, la última hora
$sql = "SELECT Fecha, Hora FROM pedidos_x_dia WHERE Fecha IS NOT NULL AND Hora IS NOT NULL ORDER BY Fecha DESC, Hora DESC LIMIT 1";
$res = $mysqli->query($sql);
if ($res && ($row = $res->fetch_assoc())) {
    $fecha = $row['Fecha']; // formato YYYY-MM-DD
    $hora  = $row['Hora'];  // formato HH:MM:SS
    $ts = strtotime($fecha . ' ' . $hora);
    if ($ts !== false) {
        echo 'Ultima actualizacion: ' . date('d/m/Y H:i', $ts);
        exit;
    }
}

echo 'Ultima actualizacion: -';
