<?php
require_once __DIR__ . '/require_login.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/dias_habiles_helper.php';

header_remove('X-Powered-By');

$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');
$rol = strtoupper((string)($_SESSION['rol'] ?? ''));
$isAdmin = in_array($rol, ['ADMIN', 'ADMINISTRADOR'], true);
if (!$isAdmin) {
    http_response_code(403);
    echo 'Acceso denegado';
    exit;
}

function hdh($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$anio = isset($_REQUEST['anio']) ? (int)$_REQUEST['anio'] : (int)date('Y');
$mes = isset($_REQUEST['mes']) ? (int)$_REQUEST['mes'] : (int)date('n');
if ($anio < 2000 || $anio > 2100) $anio = (int)date('Y');
if ($mes < 1 || $mes > 12) $mes = (int)date('n');

if ($action === 'toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $fecha = trim((string)($_POST['fecha'] ?? ''));
    $habil = isset($_POST['habil']) && (string)$_POST['habil'] === '1' ? 1 : 0;
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $fecha);
    if (!$dt) {
        echo json_encode(['ok' => false, 'error' => 'PARAMS']);
        exit;
    }
    $anio = (int)$dt->format('Y');
    $mes = (int)$dt->format('n');
    seedDiasHabilesMonth($mysqli, $anio, $mes);
    $stmt = $mysqli->prepare('UPDATE dias_habiles_mes SET habil = ? WHERE fecha = ?');
    if (!$stmt) {
        echo json_encode(['ok' => false, 'error' => 'DB']);
        exit;
    }
    $stmt->bind_param('is', $habil, $fecha);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['ok' => $ok ? true : false]);
    exit;
}

if ($action === 'reset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    seedDiasHabilesMonth($mysqli, $anio, $mes);
    $items = getDiasHabilesMonth($mysqli, $anio, $mes);
    $stmt = $mysqli->prepare('UPDATE dias_habiles_mes SET habil = ? WHERE fecha = ?');
    if (!$stmt) {
        echo json_encode(['ok' => false, 'error' => 'DB']);
        exit;
    }
    foreach ($items as $fecha => $_enabled) {
        $dow = (int)date('N', strtotime($fecha));
        $habil = ($dow === 7) ? 0 : 1;
        $stmt->bind_param('is', $habil, $fecha);
        $stmt->execute();
    }
    $stmt->close();
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'list') {
    seedDiasHabilesMonth($mysqli, $anio, $mes);
    $items = getDiasHabilesMonth($mysqli, $anio, $mes);
    $monthNames = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
    $diasSemana = [1=>'Lunes',2=>'Martes',3=>'Miércoles',4=>'Jueves',5=>'Viernes',6=>'Sábado',7=>'Domingo'];
    $totalHabiles = 0;
    ob_start();
    echo '<div style="display:flex; align-items:center; gap:10px; margin-bottom:10px; flex-wrap:wrap;">';
    echo '<strong>' . hdh($monthNames[$mes] ?? $mes) . ' ' . hdh($anio) . '</strong>';
    echo '<span style="color:#666;">Configura qué días cuentan como hábiles para el proyectado y la gráfica mensual.</span>';
    echo '<button type="button" id="btn-reset-dias-habiles" style="margin-left:auto; background:#6c757d;">Restaurar mes</button>';
    echo '</div>';
    echo '<table><thead><tr><th>Fecha</th><th>Día</th><th>Hábil</th></tr></thead><tbody>';
    foreach ($items as $fecha => $habil) {
        if ($habil) $totalHabiles++;
        $dow = (int)date('N', strtotime($fecha));
        echo '<tr data-fecha="' . hdh($fecha) . '">';
        echo '<td>' . hdh(date('d/m/Y', strtotime($fecha))) . '</td>';
        echo '<td>' . hdh($diasSemana[$dow] ?? '') . '</td>';
        echo '<td style="text-align:center"><input type="checkbox" class="dh-toggle" ' . ($habil ? 'checked' : '') . '></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '<p style="margin-top:10px; color:#555;"><strong>Total hábiles:</strong> ' . $totalHabiles . '</p>';
    header('Content-Type: text/html; charset=utf-8');
    echo ob_get_clean();
    exit;
}

http_response_code(400);
echo 'Acción no válida';
