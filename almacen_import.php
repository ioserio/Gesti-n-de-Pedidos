<?php
require_once __DIR__ . '/require_login.php';
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

// Tabla principal (también se crea en API por si acaso)
$mysqli->query("CREATE TABLE IF NOT EXISTS almacen_moldes_diarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fecha DATE NOT NULL,
  producto_codigo VARCHAR(32) DEFAULT NULL,
  producto_nombre VARCHAR(255) DEFAULT NULL,
  categoria VARCHAR(64) DEFAULT NULL,
  unidad VARCHAR(16) DEFAULT NULL,
  cantidad DECIMAL(12,2) DEFAULT NULL,
  p_pro DECIMAL(12,2) DEFAULT NULL,
  p_rea DECIMAL(12,2) DEFAULT NULL,
  cliente_codigo VARCHAR(64) DEFAULT NULL,
  cliente_nombre VARCHAR(255) DEFAULT NULL,
  camion VARCHAR(64) DEFAULT NULL,
    cod_vendedor VARCHAR(10) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Asegurar columna cod_vendedor si la tabla existía previamente sin ella
try {
        $res = $mysqli->query("SHOW COLUMNS FROM almacen_moldes_diarios LIKE 'cod_vendedor'");
        if ($res && $res->num_rows === 0) {
                @$mysqli->query("ALTER TABLE almacen_moldes_diarios ADD COLUMN cod_vendedor VARCHAR(10) DEFAULT NULL, ADD INDEX idx_vend (cod_vendedor)");
        }
        if ($res) { $res->close(); }
} catch (Throwable $e) { /* noop */ }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Método no permitido';
    exit;
}

$fecha = isset($_POST['fecha']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['fecha']) ? $_POST['fecha'] : date('Y-m-d');
$truncate = isset($_POST['truncate']) && ($_POST['truncate'] === '1' || $_POST['truncate'] === 'on');

if (!isset($_FILES['xls']) || !is_uploaded_file($_FILES['xls']['tmp_name'])) {
    http_response_code(400);
    echo 'Archivo XLS/XLSX requerido';
    exit;
}

// Opcional: importar mapeo CODIGO->CAMION desde segundo archivo (XLSX)
$camMap = [];
if (isset($_FILES['map_camion']) && is_uploaded_file($_FILES['map_camion']['tmp_name'])) {
    try {
        $mapSS = IOFactory::load($_FILES['map_camion']['tmp_name']);
        $sheetM = $mapSS->getActiveSheet();
        // Formato definido: C = CODIGO, E = CAMION; datos desde fila 2
        $maxR = $sheetM->getHighestRow();
        for ($r = 2; $r <= $maxR; $r++) {
            $code = trim((string)$sheetM->getCell('C'.$r)->getCalculatedValue());
            $cam  = trim((string)$sheetM->getCell('E'.$r)->getCalculatedValue());
            if ($code !== '') { $camMap[$code] = $cam; }
        }
    } catch (Throwable $e) { /* ignore mapping errors */ }
}

// Reset día si se pidió
if ($truncate) {
    $stmtDel = $mysqli->prepare('DELETE FROM almacen_moldes_diarios WHERE fecha = ?');
    if ($stmtDel) { $stmtDel->bind_param('s', $fecha); $stmtDel->execute(); $stmtDel->close(); }
}

// Parse XLSX principal (formato plano como en Imagen 1)
$tmp = $_FILES['xls']['tmp_name'];
try {
    $ss = IOFactory::load($tmp);
    $sheet = $ss->getActiveSheet();

    // Columnas fijas:
    // A: CodigoCliente, B: NombreCliente, E: FechaPedido, F: CodigoProd, G: NombreProd,
    // H: Unidad, I: Cantidad, J: PesoPromed (usamos como P.Pro), L: PesoRealTot (P.Rea), M: CodigoVendedor, N: Categoria

    $startRow = 2; // encabezados en la fila 1
    $inserted = 0;
    $stmt = $mysqli->prepare('INSERT INTO almacen_moldes_diarios (fecha, producto_codigo, producto_nombre, categoria, unidad, cantidad, p_pro, p_rea, cliente_codigo, cliente_nombre, cod_vendedor, camion) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    if (!$stmt) { throw new Exception('DB prepare failed'); }

    $maxR = $sheet->getHighestRow();
    for ($r = $startRow; $r <= $maxR; $r++) {
        $vend   = trim((string)$sheet->getCell('M'.$r)->getCalculatedValue());
        $cliCod = trim((string)$sheet->getCell('A'.$r)->getCalculatedValue());
        $cliNom = trim((string)$sheet->getCell('B'.$r)->getCalculatedValue());
        $fecX   = trim((string)$sheet->getCell('E'.$r)->getCalculatedValue());
        $prodCod= trim((string)$sheet->getCell('F'.$r)->getCalculatedValue());
        $prodNom= trim((string)$sheet->getCell('G'.$r)->getCalculatedValue());
        $unidad = trim((string)$sheet->getCell('H'.$r)->getCalculatedValue());
        $cant   = trim((string)$sheet->getCell('I'.$r)->getCalculatedValue());
        $pPro   = trim((string)$sheet->getCell('J'.$r)->getCalculatedValue());
        $pRea   = trim((string)$sheet->getCell('L'.$r)->getCalculatedValue());
        $cat    = trim((string)$sheet->getCell('N'.$r)->getCalculatedValue());
        if ($cliCod === '' && $prodNom === '' && $cant === '') { continue; }
                // Fecha: usar SIEMPRE la seleccionada en el formulario
                $fechaRow = $fecha;
        $cantF = ($cant !== '' ? (float)preg_replace('/[^0-9\.\-]/','', $cant) : null);
        $pProF = ($pPro !== '' ? (float)preg_replace('/[^0-9\.\-]/','', $pPro) : null);
        $pReaF = ($pRea !== '' ? (float)preg_replace('/[^0-9\.\-]/','', $pRea) : null);
        $camion = isset($camMap[$cliCod]) ? $camMap[$cliCod] : null;
        $stmt->bind_param('sssssdddssss', $fechaRow, $prodCod, $prodNom, $cat, $unidad, $cantF, $pProF, $pReaF, $cliCod, $cliNom, $vend, $camion);
        $ok = $stmt->execute(); if ($ok) $inserted++;
    }
    $stmt->close();

    echo '<div class="msg-ok">Importados ' . h($inserted) . ' registros.</div>';
    echo '<a href="index.php">Volver</a>';
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Error procesando XLSX: ' . h($e->getMessage());
}
