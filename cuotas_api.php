<?php
require_once __DIR__ . '/require_login.php';
require_once 'conexion.php';

// Ensure table exists
// Tabla legacy (vigente actual por día) - se mantiene para compatibilidad
$mysqli->query("CREATE TABLE IF NOT EXISTS cuotas_vendedor (
    Cod_Vendedor VARCHAR(10) NOT NULL,
    Dia_Semana TINYINT NOT NULL,
    Cuota DECIMAL(12,2) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (Cod_Vendedor, Dia_Semana)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Nueva tabla histórica por semana (vigente_desde = lunes de la semana o cualquier fecha de inicio)
$mysqli->query("CREATE TABLE IF NOT EXISTS cuotas_vendedor_hist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    Cod_Vendedor VARCHAR(10) NOT NULL,
    Dia_Semana TINYINT NOT NULL,
    Cuota DECIMAL(12,2) NOT NULL DEFAULT 0,
    vigente_desde DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_lookup (Cod_Vendedor, Dia_Semana, vigente_desde)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'list';
$sort = isset($_REQUEST['sort']) ? trim($_REQUEST['sort']) : 'cod'; // 'cod' | 'dia'

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $cod = isset($_POST['cod_vendedor']) ? trim($_POST['cod_vendedor']) : '';
    $dia = isset($_POST['dia_semana']) ? intval($_POST['dia_semana']) : 0;
    $cuota = isset($_POST['cuota']) ? floatval($_POST['cuota']) : 0;
    $vigente = isset($_POST['vigente_desde']) && $_POST['vigente_desde'] !== '' ? $_POST['vigente_desde'] : date('Y-m-d');
    $fullWeek = isset($_POST['full_week']) && ($_POST['full_week'] === '1' || $_POST['full_week'] === 'on');
    // Si aplica a toda la semana, anclar siempre al lunes de esa semana
    if ($fullWeek) {
        $ts = strtotime($vigente);
        if ($ts !== false) {
            // monday this week ancla al lunes incluso si ya es lunes
            $vigente = date('Y-m-d', strtotime('monday this week', $ts));
        }
    }

    // Normalize vendor code: remove leading zeros then pad to 3 digits
    $codNoZeros = ltrim($cod, '0');
    if ($codNoZeros === '') { $codNoZeros = '0'; }
    $codNorm = str_pad($codNoZeros, 3, '0', STR_PAD_LEFT);

    if ($codNorm && $cuota >= 0) {
        $diasToInsert = [];
        if ($fullWeek) {
            $diasToInsert = [1,2,3,4,5,6,7];
        } else {
            if ($dia < 1 || $dia > 7) { http_response_code(400); echo 'Parámetros inválidos'; exit; }
            $diasToInsert = [$dia];
        }
        // Insertar en histórico (no se borra nada)
        $stmtH = $mysqli->prepare("INSERT INTO cuotas_vendedor_hist (Cod_Vendedor, Dia_Semana, Cuota, vigente_desde) VALUES (?,?,?,?)");
        foreach ($diasToInsert as $d) {
            $stmtH->bind_param('sids', $codNorm, $d, $cuota, $vigente);
            $stmtH->execute();
        }
        $stmtH->close();
        // Actualizar tabla legacy a modo 'vigente actual'
        $stmtL = $mysqli->prepare("INSERT INTO cuotas_vendedor (Cod_Vendedor, Dia_Semana, Cuota) VALUES (?,?,?) ON DUPLICATE KEY UPDATE Cuota=VALUES(Cuota)");
        foreach ($diasToInsert as $d) {
            $stmtL->bind_param('sid', $codNorm, $d, $cuota);
            $stmtL->execute();
        }
        $stmtL->close();
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

if ($action === 'bulk' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $raw = isset($_POST['items']) ? (string)$_POST['items'] : '';
    $items = json_decode($raw, true);
    if (!is_array($items)) { echo json_encode(['ok'=>false,'error'=>'BAD_PAYLOAD']); $mysqli->close(); exit; }
    // Prepare statements
    $stmtH = $mysqli->prepare("INSERT INTO cuotas_vendedor_hist (Cod_Vendedor, Dia_Semana, Cuota, vigente_desde) VALUES (?,?,?,?)");
    $stmtL = $mysqli->prepare("INSERT INTO cuotas_vendedor (Cod_Vendedor, Dia_Semana, Cuota) VALUES (?,?,?) ON DUPLICATE KEY UPDATE Cuota=VALUES(Cuota)");
    if (!$stmtH || !$stmtL) { echo json_encode(['ok'=>false,'error'=>'DB_STMT']); $mysqli->close(); exit; }
    $saved = 0; $skipped = 0;
    foreach ($items as $it) {
        $cod = isset($it['cod']) ? trim((string)$it['cod']) : '';
        $dia = isset($it['dia']) ? (int)$it['dia'] : 0;
        $cuota = isset($it['cuota']) ? (float)$it['cuota'] : 0.0;
        $vigente = isset($it['vigente_desde']) ? (string)$it['vigente_desde'] : date('Y-m-d');
        // Normalizar código 3 dígitos
        $codNoZeros = ltrim($cod, '0'); if ($codNoZeros==='') $codNoZeros='0';
        $codNorm = str_pad($codNoZeros, 3, '0', STR_PAD_LEFT);
        if ($dia < 1 || $dia > 7) { $skipped++; continue; }
        if ($cuota <= 0) { $skipped++; continue; }
        // Insert histórico
        $stmtH->bind_param('sids', $codNorm, $dia, $cuota, $vigente);
        if (!$stmtH->execute()) { $skipped++; continue; }
        // Actualizar vigente actual
        $stmtL->bind_param('sid', $codNorm, $dia, $cuota);
        if (!$stmtL->execute()) { $skipped++; continue; }
        $saved++;
    }
    $stmtH->close(); $stmtL->close();
    echo json_encode(['ok'=>true,'saved'=>$saved,'skipped'=>$skipped]);
    $mysqli->close();
    exit;
}

// List view (HTML table)
if ($action === 'list') {
    // Mostrar última cuota vigente (por fecha) para cada Cod/Día
    $order = ($sort === 'dia') ? 'v.Dia_Semana ASC, v.Cod_Vendedor ASC' : 'v.Cod_Vendedor ASC, v.Dia_Semana ASC';
    $sql = "SELECT v.Cod_Vendedor, v.Dia_Semana, v.Cuota, (
                SELECT MAX(vigente_desde) FROM cuotas_vendedor_hist h
                WHERE h.Cod_Vendedor=v.Cod_Vendedor AND h.Dia_Semana=v.Dia_Semana
            ) AS ult_desde
            FROM cuotas_vendedor v
            ORDER BY $order";
    $result = $mysqli->query($sql);
    echo '<table>';
    echo '<tr><th colspan="5" style="text-align:left; background:#e6f2ff; font-size:17px;">Cuotas registradas (vigente actual)</th></tr>';
    // Encabezados con opciones de ordenamiento
    $hdrCod = '<a href="#" class="q-sort" data-sort="cod" title="Ordenar por vendedor">Cod_Vendedor</a>';
    $hdrDia = '<a href="#" class="q-sort" data-sort="dia" title="Ordenar por día">Día</a>';
    if ($sort === 'cod') { $hdrCod = '<strong>Cod_Vendedor ▲</strong>'; }
    if ($sort === 'dia') { $hdrDia = '<strong>Día ▲</strong>'; }
    echo '<tr><th>' . $hdrCod . '</th><th>' . $hdrDia . '</th><th>Cuota (S/)</th><th>Vigente desde</th><th>Acciones</th></tr>';
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
            echo '<td>'. h($row['ult_desde'] ?: '-') .'</td>';
            echo '<td><button data-del="1" data-cod="'. $cod .'" data-dia="'. $dia .'" style="background:#dc3545;">Eliminar</button></td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="5">No hay cuotas registradas.</td></tr>';
    }
    echo '</table>';
    $mysqli->close();
    exit;
}

http_response_code(400);
$mysqli->close();
echo 'Acción no válida';
