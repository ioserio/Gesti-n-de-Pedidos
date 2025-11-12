<?php
require_once __DIR__ . '/require_login.php';
require_once 'conexion.php';
header('Content-Type: application/json; charset=utf-8');

$cod = isset($_GET['cod']) ? trim($_GET['cod']) : '';
$dia = isset($_GET['dia']) ? intval($_GET['dia']) : 0;

if ($cod === '' || $dia < 1 || $dia > 7) {
    http_response_code(400);
    echo json_encode([]);
    exit;
}

// Normalizar variantes del código (raw, sin ceros, padded3)
$vdRaw = $cod;
$vdNoZeros = ltrim($vdRaw, '0'); if ($vdNoZeros === '') { $vdNoZeros = '0'; }
$vdPad3 = str_pad($vdNoZeros, 3, '0', STR_PAD_LEFT);

// Consultar histórico; intentamos con padded por defecto, pero incluimos variantes
$sql = "SELECT Cod_Vendedor, Dia_Semana, Cuota, vigente_desde, created_at
        FROM cuotas_vendedor_hist
        WHERE Dia_Semana=? AND Cod_Vendedor IN (?,?,?)
        ORDER BY vigente_desde DESC, id DESC";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param('isss', $dia, $vdRaw, $vdNoZeros, $vdPad3);
$stmt->execute();
$res = $stmt->get_result();
$out = [];
while ($row = $res->fetch_assoc()) {
    $out[] = $row;
}
$stmt->close();
$mysqli->close();
echo json_encode($out);
