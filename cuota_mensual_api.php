<?php
require_once __DIR__ . '/require_login.php';
require_once __DIR__ . '/conexion.php';

header_remove('X-Powered-By');

$mysqli->query("CREATE TABLE IF NOT EXISTS cuotas_mensuales_global (
    id INT AUTO_INCREMENT PRIMARY KEY,
    Anio SMALLINT NOT NULL,
    Mes TINYINT NOT NULL,
    Cuota DECIMAL(12,2) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cuota_mensual_global (Anio, Mes),
    KEY idx_anio_mes (Anio, Mes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$action = isset($_REQUEST['action']) ? trim((string)$_REQUEST['action']) : 'list';

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $anio = isset($_POST['anio']) ? (int)$_POST['anio'] : 0;
    $mes = isset($_POST['mes']) ? (int)$_POST['mes'] : 0;
    $cuota = isset($_POST['cuota']) ? (float)$_POST['cuota'] : -1;

    if ($anio < 2000 || $anio > 2100 || $mes < 1 || $mes > 12 || $cuota < 0) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'PARAMS']);
        $mysqli->close();
        exit;
    }

    if ($id > 0) {
        $stmt = $mysqli->prepare("UPDATE cuotas_mensuales_global SET Anio=?, Mes=?, Cuota=? WHERE id=?");
        if (!$stmt) { echo json_encode(['ok'=>false,'error'=>'DB']); $mysqli->close(); exit; }
        $stmt->bind_param('iidi', $anio, $mes, $cuota, $id);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            // Si chocó por unique, actualizar por clave natural
            $stmt2 = $mysqli->prepare("INSERT INTO cuotas_mensuales_global (Anio, Mes, Cuota) VALUES (?,?,?) ON DUPLICATE KEY UPDATE Cuota=VALUES(Cuota), updated_at=CURRENT_TIMESTAMP");
            if (!$stmt2) { echo json_encode(['ok'=>false,'error'=>'DB']); $mysqli->close(); exit; }
            $stmt2->bind_param('iid', $anio, $mes, $cuota);
            $ok = $stmt2->execute();
            $stmt2->close();
        }
        echo json_encode(['ok'=>$ok ? true : false]);
        $mysqli->close();
        exit;
    }

    $stmt = $mysqli->prepare("INSERT INTO cuotas_mensuales_global (Anio, Mes, Cuota) VALUES (?,?,?) ON DUPLICATE KEY UPDATE Cuota=VALUES(Cuota), updated_at=CURRENT_TIMESTAMP");
    if (!$stmt) { echo json_encode(['ok'=>false,'error'=>'DB']); $mysqli->close(); exit; }
    $stmt->bind_param('iid', $anio, $mes, $cuota);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode(['ok'=>$ok ? true : false]);
    $mysqli->close();
    exit;
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'PARAMS']);
        $mysqli->close();
        exit;
    }
    $stmt = $mysqli->prepare("DELETE FROM cuotas_mensuales_global WHERE id=?");
    if (!$stmt) { echo json_encode(['ok'=>false,'error'=>'DB']); $mysqli->close(); exit; }
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['ok'=>$ok ? true : false]);
    $mysqli->close();
    exit;
}

if ($action === 'get') {
    header('Content-Type: application/json; charset=utf-8');
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'PARAMS']);
        $mysqli->close();
        exit;
    }
    $stmt = $mysqli->prepare("SELECT id, Anio, Mes, Cuota FROM cuotas_mensuales_global WHERE id=?");
    if (!$stmt) { echo json_encode(['ok'=>false,'error'=>'DB']); $mysqli->close(); exit; }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) {
        echo json_encode(['ok'=>false,'error'=>'NOT_FOUND']);
    } else {
        echo json_encode(['ok'=>true,'row'=>$row]);
    }
    $mysqli->close();
    exit;
}

if ($action === 'list') {
    $anio = isset($_GET['anio']) ? (int)$_GET['anio'] : 0;
    $mes = isset($_GET['mes']) ? (int)$_GET['mes'] : 0;

    $where = [];
    $types = '';
    $vals = [];
    if ($anio >= 2000 && $anio <= 2100) {
        $where[] = 'Anio=?';
        $types .= 'i';
        $vals[] = $anio;
    }
    if ($mes >= 1 && $mes <= 12) {
        $where[] = 'Mes=?';
        $types .= 'i';
        $vals[] = $mes;
    }

    $sql = "SELECT id, Anio, Mes, Cuota, updated_at FROM cuotas_mensuales_global";
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY Anio DESC, Mes DESC';

    $rows = [];
    if ($where) {
        $stmt = $mysqli->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$vals);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) { $rows[] = $r; }
            $stmt->close();
        }
    } else {
        $res = $mysqli->query($sql);
        if ($res) {
            while ($r = $res->fetch_assoc()) { $rows[] = $r; }
            $res->close();
        }
    }

    echo '<table>';
    echo '<tr><th colspan="6" style="text-align:left; background:#e6f2ff; font-size:17px;">Cuota Mensual Global</th></tr>';
    echo '<tr><th>Año</th><th>Mes</th><th>Cuota (S/)</th><th>Actualizado</th><th>Editar</th><th>Eliminar</th></tr>';

    if (!$rows) {
        echo '<tr><td colspan="6">No hay cuotas mensuales registradas.</td></tr>';
    } else {
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $a = (int)$row['Anio'];
            $m = (int)$row['Mes'];
            $cuota = number_format((float)$row['Cuota'], 2, '.', ',');
            $updated = h((string)$row['updated_at']);

            echo '<tr>';
            echo '<td>' . $a . '</td>';
            echo '<td>' . $m . '</td>';
            echo '<td>' . $cuota . '</td>';
            echo '<td>' . $updated . '</td>';
            echo '<td><button type="button" class="cm-edit" data-id="' . $id . '">Editar</button></td>';
            echo '<td><button type="button" class="cm-del" data-id="' . $id . '" style="background:#dc3545;">Eliminar</button></td>';
            echo '</tr>';
        }
    }

    echo '</table>';
    $mysqli->close();
    exit;
}

http_response_code(400);
$mysqli->close();
echo 'Acción no válida';
