<?php
require_once __DIR__ . '/require_login.php';
require_once __DIR__ . '/conexion.php';

header_remove('X-Powered-By');

// Crear tabla de relación si no existe
$mysqli->query("CREATE TABLE IF NOT EXISTS vendedor_supervisor (
    codigo_vendedor VARCHAR(3) NOT NULL,
    numero_supervisor INT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (codigo_vendedor),
    INDEX idx_num_sup (numero_supervisor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'list';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $cod = isset($_POST['codigo_vendedor']) ? trim((string)$_POST['codigo_vendedor']) : '';
    $sup = isset($_POST['numero_supervisor']) && $_POST['numero_supervisor'] !== '' ? (int)$_POST['numero_supervisor'] : null;

    // Normalizar código a 3 dígitos
    $codNoZeros = ltrim($cod, '0');
    if ($codNoZeros === '') { $codNoZeros = '0'; }
    $codNorm = str_pad($codNoZeros, 3, '0', STR_PAD_LEFT);

    if ($codNorm === '') { echo json_encode(['ok'=>false,'error'=>'PARAMS']); exit; }

    if ($sup === null) {
        // Eliminar asignación
        $stmt = $mysqli->prepare('DELETE FROM vendedor_supervisor WHERE codigo_vendedor = ?');
        if ($stmt) { $stmt->bind_param('s', $codNorm); $ok = $stmt->execute(); $stmt->close(); }
        else { $ok = false; }
        echo json_encode(['ok'=>$ok?true:false, 'deleted'=>true]);
        exit;
    } else {
        // Upsert asignación
        $stmt = $mysqli->prepare('INSERT INTO vendedor_supervisor (codigo_vendedor, numero_supervisor) VALUES (?,?)
            ON DUPLICATE KEY UPDATE numero_supervisor = VALUES(numero_supervisor)');
        if (!$stmt) { echo json_encode(['ok'=>false,'error'=>'DB']); exit; }
        $stmt->bind_param('si', $codNorm, $sup);
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode(['ok'=>$ok?true:false]);
        exit;
    }
}

if ($action === 'list') {
    // Cargar catálogo de supervisores
    $supers = [];
    $rs = $mysqli->query('SELECT numero, nombre FROM sup_ctacte ORDER BY numero ASC');
    if ($rs) {
        while ($row = $rs->fetch_assoc()) { $supers[] = $row; }
        $rs->close();
    }

    // Traer vendedores con su asignación (si existiera)
    $sql = "SELECT v.codigo AS codigo, v.nombre AS vendedor, vs.numero_supervisor AS sup_num, s.nombre AS sup_nom
            FROM vendedores v
            LEFT JOIN vendedor_supervisor vs ON vs.codigo_vendedor = v.codigo
            LEFT JOIN sup_ctacte s ON s.numero = vs.numero_supervisor
            ORDER BY v.codigo ASC";
    $res = $mysqli->query($sql);

    // Render HTML simple (tabla editable)
    ob_start();
    echo '<table>';
    echo '<tr><th colspan="4" style="text-align:left; background:#e6f2ff; font-size:17px;">Asignar Supervisor por Vendedor</th></tr>';
    echo '<tr><th>Código</th><th>Vendedor</th><th>Supervisor</th><th>Acción</th></tr>';
    if ($res && $res->num_rows > 0) {
        while ($row = $res->fetch_assoc()) {
            $cod = (string)$row['codigo'];
            $vend = (string)$row['vendedor'];
            $supNum = isset($row['sup_num']) ? (int)$row['sup_num'] : null;
            echo '<tr data-cod="'.h($cod).'">';
            echo '<td>'.h($cod).'</td>';
            echo '<td>'.h($vend).'</td>';
            // Select de supervisores
            echo '<td>';
            echo '<select class="sup-select" data-cod="'.h($cod).'">';
            echo '<option value="">-- Sin supervisor --</option>';
            foreach ($supers as $s) {
                $n = (int)$s['numero'];
                $nom = (string)$s['nombre'];
                $sel = ($supNum !== null && $n === $supNum) ? ' selected' : '';
                echo '<option value="'.h($n).'"'.$sel.'>'.h($n.' - '.$nom).'</option>';
            }
            echo '</select>';
            echo '</td>';
            echo '<td><button type="button" class="vs-clear" data-cod="'.h($cod).'" style="background:#dc3545;">Quitar</button></td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="4">No hay vendedores registrados.</td></tr>';
    }
    echo '</table>';
    $html = ob_get_clean();
    if ($res) $res->close();
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    $mysqli->close();
    exit;
}

if ($action === 'options') {
    header('Content-Type: application/json');
    $out = ['ok'=>true, 'supervisores'=>[], 'vendedores'=>[]];
    // Supervisores
    $rs = $mysqli->query('SELECT numero, nombre FROM sup_ctacte ORDER BY numero ASC');
    if ($rs) {
        while ($r = $rs->fetch_assoc()) {
            $out['supervisores'][] = [
                'numero' => (int)$r['numero'],
                'nombre' => (string)$r['nombre']
            ];
        }
        $rs->close();
    }
    // Vendedores por supervisor si se pide
    if (isset($_GET['numero_supervisor']) && $_GET['numero_supervisor'] !== '') {
        $sup = (int)$_GET['numero_supervisor'];
        $sql = "SELECT v.codigo, v.nombre
                FROM vendedores v
                INNER JOIN vendedor_supervisor vs ON vs.codigo_vendedor = v.codigo
                WHERE vs.numero_supervisor = ?
                ORDER BY v.codigo ASC";
        if ($stmt = $mysqli->prepare($sql)) {
            $stmt->bind_param('i', $sup);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $out['vendedores'][] = [ 'codigo' => (string)$row['codigo'], 'nombre' => (string)$row['nombre'] ];
            }
            $stmt->close();
        }
    }
    echo json_encode($out);
    $mysqli->close();
    exit;
}

http_response_code(400);
$mysqli->close();
echo 'Acción no válida';
