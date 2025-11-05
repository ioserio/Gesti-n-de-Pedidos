<?php
require_once __DIR__ . '/require_login.php';
require_once __DIR__ . '/conexion.php';

header_remove('X-Powered-By');

// Crear tabla si no existe
$mysqli->query("CREATE TABLE IF NOT EXISTS rutas_vendedor (
    Cod_Vendedor VARCHAR(10) NOT NULL,
    Dia_Semana TINYINT NOT NULL,
    Zona VARCHAR(32) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (Cod_Vendedor, Dia_Semana)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'list';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $cod = isset($_POST['cod_vendedor']) ? trim((string)$_POST['cod_vendedor']) : '';
    $dia = isset($_POST['dia_semana']) ? (int)$_POST['dia_semana'] : 0;
    $zona = isset($_POST['zona']) ? trim((string)$_POST['zona']) : '';

    // Normalizar vendedor a 3 dígitos
    $codNoZeros = ltrim($cod, '0');
    if ($codNoZeros === '') { $codNoZeros = '0'; }
    $codNorm = str_pad($codNoZeros, 3, '0', STR_PAD_LEFT);

    if ($codNorm !== '' && $dia >= 1 && $dia <= 7 && $zona !== '') {
        // Upsert: una ruta por vendedor y día
        $stmt = $mysqli->prepare('INSERT INTO rutas_vendedor (Cod_Vendedor, Dia_Semana, Zona) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE Zona=VALUES(Zona)');
        if ($stmt) {
            $stmt->bind_param('sis', $codNorm, $dia, $zona);
            $ok = $stmt->execute();
            $stmt->close();
            if (!$ok) { http_response_code(500); echo 'Error al guardar.'; exit; }
        } else {
            http_response_code(500); echo 'DB error'; exit;
        }
    } else {
        http_response_code(400); echo 'Parámetros inválidos'; exit;
    }
    $action = 'list';
}

if ($action === 'delete') {
    $cod = isset($_GET['cod']) ? trim((string)$_GET['cod']) : '';
    $dia = isset($_GET['dia']) ? (int)$_GET['dia'] : 0;
    if ($cod !== '' && $dia >= 1 && $dia <= 7) {
        $stmt = $mysqli->prepare('DELETE FROM rutas_vendedor WHERE Cod_Vendedor=? AND Dia_Semana=?');
        if ($stmt) { $stmt->bind_param('si', $cod, $dia); $stmt->execute(); $stmt->close(); }
    }
    $action = 'list';
}

if ($action === 'list') {
    $dias = [1=>'Lunes',2=>'Martes',3=>'Miércoles',4=>'Jueves',5=>'Viernes',6=>'Sábado',7=>'Domingo'];
    $res = $mysqli->query('SELECT Cod_Vendedor, Dia_Semana, Zona FROM rutas_vendedor ORDER BY Cod_Vendedor ASC, Dia_Semana ASC');
    echo '<table>';
    echo '<tr><th colspan="4" style="text-align:left; background:#e6f2ff; font-size:17px;">Rutas registradas</th></tr>';
    echo '<tr><th>Cod_Vendedor</th><th>Día</th><th>Ruta/Zona</th><th>Acciones</th></tr>';
    if ($res && $res->num_rows > 0) {
        while ($row = $res->fetch_assoc()) {
            $cod = h($row['Cod_Vendedor']);
            $dia = (int)$row['Dia_Semana'];
            $nomDia = isset($dias[$dia]) ? $dias[$dia] : $dia;
            $zona = h($row['Zona']);
            echo '<tr>';
            echo '<td>'.$cod.'</td>';
            echo '<td>'.h($nomDia).'</td>';
            echo '<td>'.$zona.'</td>';
            echo '<td><button data-del="1" data-cod="'.$cod.'" data-dia="'.$dia.'" style="background:#dc3545;">Eliminar</button></td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="4">No hay rutas registradas.</td></tr>';
    }
    echo '</table>';
    if ($res) $res->close();
    $mysqli->close();
    exit;
}

http_response_code(400);
$mysqli->close();
echo 'Acción no válida';
