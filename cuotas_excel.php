<?php
require_once __DIR__ . '/require_login.php';
require_once __DIR__ . '/conexion.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

// Autoload Composer
require_once __DIR__ . '/vendor/autoload.php';

function normCod($cod){
    $cod = trim((string)$cod);
    $codNoZeros = ltrim($cod, '0');
    if ($codNoZeros === '') { $codNoZeros = '0'; }
    return str_pad($codNoZeros, 3, '0', STR_PAD_LEFT);
}

$action = isset($_GET['action']) ? $_GET['action'] : 'template';

if ($action === 'template') {
    // Build template with headers and a sample row
    $ss = new Spreadsheet();
    $ws = $ss->getActiveSheet();
    $ws->setTitle('Cuotas');
    $headers = ['Cod_Vendedor','Nombre(Optional)','Dia_Semana','Cuota','Vigente_Desde'];
    foreach ($headers as $i => $h) { $ws->setCellValueByColumnAndRow($i+1, 1, $h); }
    // Sample
    $ws->setCellValueExplicit('A2', '011', DataType::TYPE_STRING);
    $ws->setCellValue('B2', 'Juan Perez');
    $ws->setCellValue('C2', 1);
    $ws->setCellValue('D2', 100.00);
    $ws->setCellValue('E2', date('Y-m-d'));
    // Widths
    foreach (range('A','E') as $col) { $ws->getColumnDimension($col)->setAutoSize(true); }
    // Download
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="plantilla_cuotas.xlsx"');
    header('Cache-Control: max-age=0');
    $writer = new Xlsx($ss);
    $writer->save('php://output');
    $mysqli->close();
    exit;
}

if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        echo json_encode(['ok'=>false,'error'=>'NO_FILE']);
        $mysqli->close();
        exit;
    }
    $tmp = $_FILES['file']['tmp_name'];
    try {
        $spreadsheet = IOFactory::load($tmp);
    } catch (\Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>'READ_FAIL','detail'=>$e->getMessage()]);
        $mysqli->close();
        exit;
    }
    $sheet = $spreadsheet->getActiveSheet();
    $highestRow = $sheet->getHighestRow();
    $highestColumn = $sheet->getHighestColumn();
    $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

    $items = [];
    // Expect columns: A:Cod_Vendedor, B:Nombre(optional), C:Dia_Semana, D:Cuota, E:Vigente_Desde
    for ($row = 2; $row <= $highestRow; $row++) {
        $cod = (string)$sheet->getCellByColumnAndRow(1, $row)->getFormattedValue();
        $dia = (int)$sheet->getCellByColumnAndRow(3, $row)->getFormattedValue();
        $cuota = (float)$sheet->getCellByColumnAndRow(4, $row)->getCalculatedValue();
        $vigente = (string)$sheet->getCellByColumnAndRow(5, $row)->getFormattedValue();
        if ($cod === '' && $dia === 0 && $cuota === 0) { continue; }
        $codNorm = normCod($cod);
        if ($dia < 1 || $dia > 7) { continue; }
        if ($cuota <= 0) { continue; }
        // Normalize date
        if ($vigente === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $vigente)) {
            $vigente = date('Y-m-d');
        }
        $items[] = [
            'cod' => $codNorm,
            'dia' => $dia,
            'cuota' => round($cuota,2),
            'vigente_desde' => $vigente
        ];
    }
    if (!count($items)) {
        echo json_encode(['ok'=>false,'error'=>'NO_VALID_ROWS']);
        $mysqli->close();
        exit;
    }
    // Bulk insert via existing API logic
    $stmtH = $mysqli->prepare("INSERT INTO cuotas_vendedor_hist (Cod_Vendedor, Dia_Semana, Cuota, vigente_desde) VALUES (?,?,?,?)");
    $stmtL = $mysqli->prepare("INSERT INTO cuotas_vendedor (Cod_Vendedor, Dia_Semana, Cuota) VALUES (?,?,?) ON DUPLICATE KEY UPDATE Cuota=VALUES(Cuota)");
    if (!$stmtH || !$stmtL) {
        echo json_encode(['ok'=>false,'error'=>'DB_STMT']);
        $mysqli->close();
        exit;
    }
    $saved=0;$skipped=0;
    foreach ($items as $it) {
        $stmtH->bind_param('sids', $it['cod'], $it['dia'], $it['cuota'], $it['vigente_desde']);
        if (!$stmtH->execute()) { $skipped++; continue; }
        $stmtL->bind_param('sid', $it['cod'], $it['dia'], $it['cuota']);
        if (!$stmtL->execute()) { $skipped++; continue; }
        $saved++;
    }
    $stmtH->close(); $stmtL->close();
    echo json_encode(['ok'=>true,'saved'=>$saved,'skipped'=>$skipped]);
    $mysqli->close();
    exit;
}

http_response_code(400);
echo 'Acción no válida';
?>