<?php
require_once 'conexion.php';

// Ensure table exists
$mysqli->query("CREATE TABLE IF NOT EXISTS cuotas_vendedor (
    Cod_Vendedor VARCHAR(10) NOT NULL,
    Dia_Semana TINYINT NOT NULL,
    Cuota DECIMAL(12,2) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (Cod_Vendedor, Dia_Semana)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'list';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $cod = isset($_POST['cod_vendedor']) ? trim($_POST['cod_vendedor']) : '';
    $dia = isset($_POST['dia_semana']) ? intval($_POST['dia_semana']) : 0;
    $cuota = isset($_POST['cuota']) ? floatval($_POST['cuota']) : 0;

    // Normalize vendor code: remove leading zeros then pad to 3 digits
    $codNoZeros = ltrim($cod, '0');
    if ($codNoZeros === '') { $codNoZeros = '0'; }
    $codNorm = str_pad($codNoZeros, 3, '0', STR_PAD_LEFT);

    if ($codNorm && $dia >= 1 && $dia <= 7) {
        $stmt = $mysqli->prepare("INSERT INTO cuotas_vendedor (Cod_Vendedor, Dia_Semana, Cuota) VALUES (?,?,?) ON DUPLICATE KEY UPDATE Cuota=VALUES(Cuota)");
        $stmt->bind_param('sid', $codNorm, $dia, $cuota);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            http_response_code(500);
            echo 'Error al guardar.';
            exit;
        }
    } else {
        http_response_code(400);
        echo 'Parámetros inválidos';
        exit;
    }
    // Return refreshed list
    $action = 'list';
}

if ($action === 'delete') {
    $cod = isset($_GET['cod']) ? trim($_GET['cod']) : '';
    $dia = isset($_GET['dia']) ? intval($_GET['dia']) : 0;
    if ($cod && $dia) {
        $stmt = $mysqli->prepare("DELETE FROM cuotas_vendedor WHERE Cod_Vendedor=? AND Dia_Semana=?");
        $stmt->bind_param('si', $cod, $dia);
        $stmt->execute();
        $stmt->close();
    }
    $action = 'list';
}

// List view (HTML table)
if ($action === 'list') {
    $result = $mysqli->query("SELECT Cod_Vendedor, Dia_Semana, Cuota FROM cuotas_vendedor ORDER BY Cod_Vendedor ASC, Dia_Semana ASC");
    echo '<table>';
    echo '<tr><th colspan="4" style="text-align:left; background:#e6f2ff; font-size:17px;">Cuotas registradas</th></tr>';
    echo '<tr><th>Cod_Vendedor</th><th>Día</th><th>Cuota (S/)</th><th>Acciones</th></tr>';
    $dias = [1=>'Lunes',2=>'Martes',3=>'Miércoles',4=>'Jueves',5=>'Viernes',6=>'Sábado',7=>'Domingo'];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $cod = h($row['Cod_Vendedor']);
            $dia = intval($row['Dia_Semana']);
            $nomDia = isset($dias[$dia]) ? $dias[$dia] : $dia;
            $cuota = number_format((float)$row['Cuota'], 2, '.', ',');
            echo '<tr>';
            echo '<td>'. $cod .'</td>';
            echo '<td>'. h($nomDia) .'</td>';
            echo '<td>'. $cuota .'</td>';
            echo '<td><button data-del="1" data-cod="'. $cod .'" data-dia="'. $dia .'" style="background:#dc3545;">Eliminar</button></td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="4">No hay cuotas registradas.</td></tr>';
    }
    echo '</table>';
    $mysqli->close();
    exit;
}

http_response_code(400);
$mysqli->close();
echo 'Acción no válida';
