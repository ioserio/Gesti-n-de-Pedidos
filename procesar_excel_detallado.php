<?php
require_once __DIR__ . '/require_login.php';
require_once __DIR__ . '/init.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$isAjax = (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest')
    || (stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false);

function respondImportDet($ok, $message, $statusCode = 200, $extra = []) {
    global $isAjax;
    if ($isAjax) {
        http_response_code((int)$statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(array_merge(['ok' => (bool)$ok, 'message' => (string)$message], $extra), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($ok) {
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Importacion exitosa</title><link rel="stylesheet" href="' . htmlspecialchars(asset_url('estilos.css'), ENT_QUOTES, 'UTF-8') . '"></head><body><div class="container">';
        echo '<h2>Datos detallados importados correctamente</h2>';
        echo '<p>' . htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') . '</p>';
        echo '<a href="index.php">Volver</a>';
        echo '</div></body></html>';
        exit;
    }

    http_response_code((int)$statusCode);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Error</title><link rel="stylesheet" href="' . htmlspecialchars(asset_url('estilos.css'), ENT_QUOTES, 'UTF-8') . '"></head><body><div class="container">';
    echo '<h2>Error al importar pedidos detallados</h2>';
    echo '<pre style="white-space:pre-wrap;background:#f8f8f8;padding:12px;border:1px solid #ddd;border-radius:6px;">' . htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') . '</pre>';
    echo '<a href="index.php">Volver</a>';
    echo '</div></body></html>';
    exit;
}

function normalizeDateDet($value): ?string {
    if ($value === null || $value === '') {
        return null;
    }
    if (is_numeric($value)) {
        try {
            $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value);
            return $dt->format('Y-m-d');
        } catch (Throwable $e) {
        }
    }
    $value = trim((string)$value);
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }
    $ts = strtotime($value);
    return $ts ? date('Y-m-d', $ts) : null;
}

function normalizeTimeDet($value): ?string {
    if ($value === null || $value === '') {
        return null;
    }
    if (is_numeric($value)) {
        try {
            $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value);
            return $dt->format('H:i:s');
        } catch (Throwable $e) {
        }
    }
    $value = trim((string)$value);
    $ts = strtotime($value);
    return $ts ? date('H:i:s', $ts) : null;
}

function toDecimalOrNull($value): ?string {
    if ($value === null || $value === '') {
        return null;
    }
    $value = str_replace(',', '', trim((string)$value));
    if ($value === '' || !is_numeric($value)) {
        return null;
    }
    return number_format((float)$value, 2, '.', '');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['archivo_excel_detallado'])) {
    if ($isAjax) {
        respondImportDet(false, 'Solicitud invalida para importacion.', 405);
    }
    header('Location: index.php');
    exit;
}

try {
    $archivoTmp = $_FILES['archivo_excel_detallado']['tmp_name'] ?? '';
    if (!is_uploaded_file($archivoTmp)) {
        respondImportDet(false, 'No se recibio un archivo valido.', 400);
    }

    $spreadsheet = IOFactory::load($archivoTmp);
    $hoja = $spreadsheet->getActiveSheet();
    $datos = $hoja->toArray();
    if (!$datos || count($datos) < 2) {
        respondImportDet(false, 'El archivo no contiene filas de datos para importar.', 400);
    }

    require_once 'conexion.php';

    $mysqli->query("CREATE TABLE IF NOT EXISTS pedidos_x_dia_detallado (
        id INT AUTO_INCREMENT PRIMARY KEY,
        numcp VARCHAR(50) DEFAULT NULL,
        hora TIME DEFAULT NULL,
        fecha DATE DEFAULT NULL,
        cod_vendedor VARCHAR(20) DEFAULT NULL,
        nom_vendedor VARCHAR(100) DEFAULT NULL,
        codigo VARCHAR(50) DEFAULT NULL,
        nombre VARCHAR(150) DEFAULT NULL,
        total_igv DECIMAL(15,2) DEFAULT NULL,
        zona VARCHAR(50) DEFAULT NULL,
        cod_producto VARCHAR(50) DEFAULT NULL,
        descripcion VARCHAR(255) DEFAULT NULL,
        cantidad DECIMAL(15,2) DEFAULT NULL,
        valorunitario DECIMAL(15,2) DEFAULT NULL,
        ffvv VARCHAR(50) DEFAULT NULL,
        peso DECIMAL(15,2) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_pedidos_det_fecha (fecha),
        KEY idx_pedidos_det_numcp (numcp),
        KEY idx_pedidos_det_vendedor (cod_vendedor)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $fechaExcel = null;
    for ($i = 1; $i < count($datos); $i++) {
        $fila = $datos[$i];
        if (isset($fila[2]) && $fila[2] !== null && $fila[2] !== '') {
            $fechaExcel = normalizeDateDet($fila[2]);
            if ($fechaExcel) {
                break;
            }
        }
    }

    if ($fechaExcel) {
        $stmtDelete = $mysqli->prepare('DELETE FROM pedidos_x_dia_detallado WHERE fecha = ?');
        if ($stmtDelete) {
            $stmtDelete->bind_param('s', $fechaExcel);
            $stmtDelete->execute();
            $stmtDelete->close();
        }
    }

    $sql = 'INSERT INTO pedidos_x_dia_detallado (numcp, hora, fecha, cod_vendedor, nom_vendedor, codigo, nombre, total_igv, zona, cod_producto, descripcion, cantidad, valorunitario, ffvv, peso) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new Exception('Error en la preparacion de la consulta: ' . $mysqli->error);
    }

    $procesadas = 0;
    for ($i = 1; $i < count($datos); $i++) {
        $fila = array_pad($datos[$i], 15, null);
        $numcp = ($fila[0] !== null && $fila[0] !== '') ? trim((string)$fila[0]) : null;
        $hora = normalizeTimeDet($fila[1]);
        $fecha = normalizeDateDet($fila[2]);
        $codVendedor = ($fila[3] !== null && $fila[3] !== '') ? trim((string)$fila[3]) : null;
        $nomVendedor = ($fila[4] !== null && $fila[4] !== '') ? trim((string)$fila[4]) : null;
        $codigo = ($fila[5] !== null && $fila[5] !== '') ? trim((string)$fila[5]) : null;
        $nombre = ($fila[6] !== null && $fila[6] !== '') ? trim((string)$fila[6]) : null;
        $totalIgv = toDecimalOrNull($fila[7]);
        $zona = ($fila[8] !== null && $fila[8] !== '') ? trim((string)$fila[8]) : null;
        $codProducto = ($fila[9] !== null && $fila[9] !== '') ? trim((string)$fila[9]) : null;
        $descripcion = ($fila[10] !== null && $fila[10] !== '') ? trim((string)$fila[10]) : null;
        $cantidad = toDecimalOrNull($fila[11]);
        $valorUnitario = toDecimalOrNull($fila[12]);
        $ffvv = ($fila[13] !== null && $fila[13] !== '') ? trim((string)$fila[13]) : null;
        $peso = toDecimalOrNull($fila[14]);

        if ($numcp === null && $fecha === null && $codProducto === null) {
            continue;
        }

        $stmt->bind_param(
            'sssssssssssssss',
            $numcp,
            $hora,
            $fecha,
            $codVendedor,
            $nomVendedor,
            $codigo,
            $nombre,
            $totalIgv,
            $zona,
            $codProducto,
            $descripcion,
            $cantidad,
            $valorUnitario,
            $ffvv,
            $peso
        );
        if (!$stmt->execute()) {
            throw new Exception('Error al guardar la fila ' . ($i + 1) . ': ' . $stmt->error);
        }
        $procesadas++;
    }

    $stmt->close();
    $mysqli->close();
    respondImportDet(true, 'Se procesaron ' . (int)$procesadas . ' filas correctamente en pedidos_x_dia_detallado.', 200, ['rows' => (int)$procesadas]);
} catch (Throwable $e) {
    respondImportDet(false, 'Error al procesar el archivo: ' . $e->getMessage(), 500);
}
