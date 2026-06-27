<?php
require_once __DIR__ . '/require_login.php';
require_once __DIR__ . '/init.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
@set_time_limit(0);
@ini_set('memory_limit', '512M');

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/conexion.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsDate;

$isAjax = (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest')
    || (stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false);

function respondImportComprobantes($ok, $message, $statusCode = 200, $extra = []) {
    global $isAjax;
    if ($isAjax) {
        http_response_code((int)$statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(array_merge(['ok' => (bool)$ok, 'message' => (string)$message], $extra), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($ok) {
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Importacion de Comprobantes Detallados</title><link rel="stylesheet" href="' . htmlspecialchars(asset_url('estilos.css'), ENT_QUOTES, 'UTF-8') . '"></head><body><div class="container">';
        echo '<h2>Comprobantes detallados importados correctamente</h2>';
        echo '<p>' . htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') . '</p>';
        echo '<a href="index.php">Volver</a>';
        echo '</div></body></html>';
        exit;
    }

    http_response_code((int)$statusCode);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Error</title><link rel="stylesheet" href="' . htmlspecialchars(asset_url('estilos.css'), ENT_QUOTES, 'UTF-8') . '"></head><body><div class="container">';
    echo '<h2>Error al importar comprobantes detallados</h2>';
    echo '<pre style="white-space:pre-wrap;background:#f8f8f8;padding:12px;border:1px solid #ddd;border-radius:6px;">' . htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') . '</pre>';
    echo '<a href="index.php">Volver</a>';
    echo '</div></body></html>';
    exit;
}

function normalize_comprobante_header($value) {
    $value = trim((string)$value);
    $map = [
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n'
    ];
    $value = strtr($value, $map);
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '', $value);
    return $value;
}

function parse_comprobante_date($value) {
    if ($value === null || $value === '') {
        return null;
    }

    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d');
    }

    if (is_numeric($value)) {
        try {
            return XlsDate::excelToDateTimeObject((float)$value)->format('Y-m-d');
        } catch (Throwable $e) {
        }
    }

    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw)) {
        return $raw;
    }

    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $raw, $match)) {
        return sprintf('%04d-%02d-%02d', (int)$match[3], (int)$match[2], (int)$match[1]);
    }

    if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $raw, $match)) {
        return sprintf('%04d-%02d-%02d', (int)$match[3], (int)$match[2], (int)$match[1]);
    }

    try {
        return (new DateTime($raw))->format('Y-m-d');
    } catch (Throwable $e) {
        return null;
    }
}

function parse_comprobante_int($value) {
    if ($value === null || $value === '') {
        return null;
    }

    if (is_bool($value)) {
        return $value ? 1 : 0;
    }

    if (is_int($value)) {
        return $value;
    }

    if (is_float($value)) {
        return (int)round($value);
    }

    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }

    $normalized = str_replace([',', ' '], ['', ''], $raw);
    if ($normalized === '' || !is_numeric($normalized)) {
        return null;
    }

    return (int)round((float)$normalized);
}

function parse_comprobante_decimal($value) {
    if ($value === null || $value === '') {
        return null;
    }

    if (is_int($value) || is_float($value)) {
        return (float)$value;
    }

    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }

    $raw = str_replace(["\u{00A0}", "\xC2\xA0", ' '], '', $raw);
    $hasComma = strpos($raw, ',') !== false;
    $hasDot = strpos($raw, '.') !== false;

    if ($hasComma && $hasDot) {
        $lastComma = strrpos($raw, ',');
        $lastDot = strrpos($raw, '.');
        if ($lastComma > $lastDot) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        } else {
            $raw = str_replace(',', '', $raw);
        }
    } elseif ($hasComma) {
        $raw = str_replace(',', '.', $raw);
    }

    if (!is_numeric($raw)) {
        return null;
    }

    return (float)$raw;
}

function parse_comprobante_text($value) {
    if ($value === null) {
        return null;
    }

    $text = trim((string)$value);
    return $text === '' ? null : $text;
}

function build_comprobante_date_from_parts($year, $month, $day) {
    if ($year === null || $month === null || $day === null) {
        return null;
    }

    $year = (int)$year;
    $month = (int)$month;
    $day = (int)$day;

    if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
        return null;
    }

    if (!checkdate($month, $day, $year)) {
        return null;
    }

    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

function bind_stmt_params($stmt, $types, array &$values) {
    $refs = [];
    foreach ($values as $index => &$value) {
        $refs[$index] = &$value;
    }
    array_unshift($refs, $types);
    return call_user_func_array([$stmt, 'bind_param'], $refs);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['archivo_comprobantes_detallados'])) {
    if ($isAjax) {
        respondImportComprobantes(false, 'Solicitud invalida para importacion.', 405);
    }
    header('Location: index.php');
    exit();
}

try {
    $archivoTmp = $_FILES['archivo_comprobantes_detallados']['tmp_name'] ?? '';

    if (!is_uploaded_file($archivoTmp)) {
        throw new Exception('No se recibio un archivo valido.');
    }

    $reader = IOFactory::createReaderForFile($archivoTmp);
    if (method_exists($reader, 'setReadDataOnly')) {
        $reader->setReadDataOnly(true);
    }

    $spreadsheet = $reader->load($archivoTmp);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray(null, true, true, false);
    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);

    if (!$rows || count($rows) < 2) {
        throw new Exception('El archivo no contiene filas de datos para importar.');
    }

    $columnDefinitions = [
        'codigovendedor' => ['aliases' => ['codigovendedor', 'CodigoVendedor'], 'parser' => 'parse_comprobante_int', 'bind' => 'i'],
        'nombrevendedor' => ['aliases' => ['nombrevendedor', 'NombreVendedor'], 'parser' => 'parse_comprobante_text', 'bind' => 's'],
        'nombregrupoventas' => ['aliases' => ['nombregrupoventas', 'NombreGrupoVentas'], 'parser' => 'parse_comprobante_text', 'bind' => 's'],
        'codigocliente' => ['aliases' => ['codigocliente', 'CodigoCliente'], 'parser' => 'parse_comprobante_int', 'bind' => 'i'],
        'nombrecliente' => ['aliases' => ['nombrecliente', 'NombreCliente'], 'parser' => 'parse_comprobante_text', 'bind' => 's'],
        'codigozona' => ['aliases' => ['codigozona', 'CodigoZona'], 'parser' => 'parse_comprobante_int', 'bind' => 'i'],
        'nombrezona' => ['aliases' => ['nombrezona', 'NombreZona'], 'parser' => 'parse_comprobante_text', 'bind' => 's'],
        'fecha' => ['aliases' => ['fecha', 'Fecha'], 'parser' => 'parse_comprobante_date', 'bind' => 's'],
        'abreviacion' => ['aliases' => ['abreviacion', 'Abreviacion'], 'parser' => 'parse_comprobante_text', 'bind' => 's'],
        'numcp' => ['aliases' => ['numcp', 'NumCp'], 'parser' => 'parse_comprobante_text', 'bind' => 's'],
        'codigoproducto' => ['aliases' => ['codigoproducto', 'CodigoProducto'], 'parser' => 'parse_comprobante_int', 'bind' => 'i'],
        'nombreproducto' => ['aliases' => ['nombreproducto', 'NombreProducto'], 'parser' => 'parse_comprobante_text', 'bind' => 's'],
        'codigomarca' => ['aliases' => ['codigomarca', 'CodigoMarca'], 'parser' => 'parse_comprobante_int', 'bind' => 'i'],
        'nombremarca' => ['aliases' => ['nombremarca', 'NombreMarca'], 'parser' => 'parse_comprobante_text', 'bind' => 's'],
        'linea' => ['aliases' => ['linea', 'Linea'], 'parser' => 'parse_comprobante_text', 'bind' => 's'],
        'bonificacion' => ['aliases' => ['bonificacion', 'Bonificacion'], 'parser' => 'parse_comprobante_int', 'bind' => 'i'],
        'codigoalmacen' => ['aliases' => ['codigoalmacen', 'CodigoAlmacen'], 'parser' => 'parse_comprobante_int', 'bind' => 'i'],
        'credito' => ['aliases' => ['credito', 'Credito'], 'parser' => 'parse_comprobante_int', 'bind' => 'i'],
        'anulado' => ['aliases' => ['anulado', 'Anulado'], 'parser' => 'parse_comprobante_int', 'bind' => 'i'],
        'tipo' => ['aliases' => ['tipo', 'Tipo'], 'parser' => 'parse_comprobante_text', 'bind' => 's'],
        'cantidad' => ['aliases' => ['cantidad', 'Cantidad'], 'parser' => 'parse_comprobante_int', 'bind' => 'i'],
        'valorunitario' => ['aliases' => ['valorunitario', 'ValorUnitario'], 'parser' => 'parse_comprobante_decimal', 'bind' => 'd'],
        'itemcostoventa' => ['aliases' => ['itemcostoventa', 'ItemCostoVenta'], 'parser' => 'parse_comprobante_decimal', 'bind' => 'd'],
        'itemvalorventa' => ['aliases' => ['itemvalorventa', 'ItemValorVenta'], 'parser' => 'parse_comprobante_decimal', 'bind' => 'd'],
        'itemtotal' => ['aliases' => ['itemtotal', 'ItemTotal'], 'parser' => 'parse_comprobante_decimal', 'bind' => 'd'],
        'ano' => ['aliases' => ['ano', 'Año'], 'parser' => 'parse_comprobante_int', 'bind' => 'i'],
        'mes' => ['aliases' => ['mes', 'Mes'], 'parser' => 'parse_comprobante_int', 'bind' => 'i'],
        'semana' => ['aliases' => ['semana', 'Semana'], 'parser' => 'parse_comprobante_int', 'bind' => 'i'],
        'dia' => ['aliases' => ['dia', 'Dia'], 'parser' => 'parse_comprobante_int', 'bind' => 'i'],
        'codigovehiculo' => ['aliases' => ['codigovehiculo', 'CodigoVehiculo'], 'parser' => 'parse_comprobante_int', 'bind' => 'i'],
        'nombrevehiculo' => ['aliases' => ['nombrevehiculo', 'NombreVehiculo'], 'parser' => 'parse_comprobante_text', 'bind' => 's'],
        'pagado' => ['aliases' => ['pagado', 'Pagado'], 'parser' => 'parse_comprobante_int', 'bind' => 'i'],
        'fechaultimopago' => ['aliases' => ['fechaultimopago', 'FechaUltimoPago'], 'parser' => 'parse_comprobante_date', 'bind' => 's'],
        'cargoempleado' => ['aliases' => ['cargoempleado', 'CargoEmpleado'], 'parser' => 'parse_comprobante_text', 'bind' => 's'],
        'peso' => ['aliases' => ['peso', 'Peso'], 'parser' => 'parse_comprobante_decimal', 'bind' => 'd'],
        'precioporpeso' => ['aliases' => ['precioporpeso', 'PrecioPorPeso'], 'parser' => 'parse_comprobante_int', 'bind' => 'i'],
    ];

    $headers = $rows[0];
    $headerMap = [];
    foreach ($headers as $index => $header) {
        $normalized = normalize_comprobante_header($header);
        if ($normalized !== '') {
            $headerMap[$normalized] = $index;
        }
    }

    $missing = [];
    $columnIndexes = [];
    foreach ($columnDefinitions as $columnName => $definition) {
        $foundIndex = null;
        foreach ($definition['aliases'] as $alias) {
            $normalizedAlias = normalize_comprobante_header($alias);
            if (array_key_exists($normalizedAlias, $headerMap)) {
                $foundIndex = $headerMap[$normalizedAlias];
                break;
            }
        }

        if ($foundIndex === null) {
            $missing[] = $columnName;
            continue;
        }

        $columnIndexes[$columnName] = $foundIndex;
    }

    if (!empty($missing)) {
        throw new Exception('Faltan columnas requeridas en el Excel: ' . implode(', ', $missing));
    }

    $createSql = "CREATE TABLE IF NOT EXISTS `comprobantes_detallados` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `codigovendedor` int(11) DEFAULT NULL,
      `nombrevendedor` varchar(255) DEFAULT NULL,
      `nombregrupoventas` varchar(255) DEFAULT NULL,
      `codigocliente` int(11) DEFAULT NULL,
      `nombrecliente` varchar(255) DEFAULT NULL,
      `codigozona` int(11) DEFAULT NULL,
      `nombrezona` varchar(255) DEFAULT NULL,
      `fecha` date DEFAULT NULL,
      `abreviacion` varchar(255) DEFAULT NULL,
      `numcp` varchar(255) DEFAULT NULL,
      `codigoproducto` int(11) DEFAULT NULL,
      `nombreproducto` varchar(255) DEFAULT NULL,
      `codigomarca` int(11) DEFAULT NULL,
      `nombremarca` varchar(255) DEFAULT NULL,
      `linea` varchar(255) DEFAULT NULL,
      `bonificacion` int(11) DEFAULT NULL,
      `codigoalmacen` int(11) DEFAULT NULL,
      `credito` int(11) DEFAULT NULL,
      `anulado` int(11) DEFAULT NULL,
      `tipo` varchar(255) DEFAULT NULL,
      `cantidad` int(11) DEFAULT NULL,
      `valorunitario` decimal(15,2) DEFAULT NULL,
      `itemcostoventa` decimal(15,2) DEFAULT NULL,
      `itemvalorventa` decimal(15,2) DEFAULT NULL,
      `itemtotal` decimal(15,2) DEFAULT NULL,
      `ano` int(11) DEFAULT NULL,
      `mes` int(11) DEFAULT NULL,
      `semana` int(11) DEFAULT NULL,
      `dia` int(11) DEFAULT NULL,
      `codigovehiculo` int(11) DEFAULT NULL,
      `nombrevehiculo` varchar(255) DEFAULT NULL,
      `pagado` int(11) DEFAULT NULL,
      `fechaultimopago` date DEFAULT NULL,
      `cargoempleado` varchar(255) DEFAULT NULL,
      `peso` decimal(15,2) DEFAULT NULL,
      `precioporpeso` int(11) DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_comprobantes_det_fecha` (`fecha`),
      KEY `idx_comprobantes_det_numcp` (`numcp`),
      KEY `idx_comprobantes_det_cliente` (`codigocliente`),
      KEY `idx_comprobantes_det_producto` (`codigoproducto`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$mysqli->query($createSql)) {
        throw new Exception('No se pudo asegurar la tabla destino: ' . $mysqli->error);
    }

    if (!empty($_POST['truncate'])) {
        if (!$mysqli->query('TRUNCATE TABLE `comprobantes_detallados`')) {
            throw new Exception('No se pudo vaciar la tabla antes de importar: ' . $mysqli->error);
        }
    }

    $columnsInOrder = array_keys($columnDefinitions);
    $insertSql = 'INSERT INTO `comprobantes_detallados` (`' . implode('`, `', $columnsInOrder) . '`) VALUES (' . implode(',', array_fill(0, count($columnsInOrder), '?')) . ')';
    $stmt = $mysqli->prepare($insertSql);
    if (!$stmt) {
        throw new Exception('Error al preparar la insercion: ' . $mysqli->error);
    }

    $bindTypes = '';
    foreach ($columnsInOrder as $columnName) {
        $bindTypes .= $columnDefinitions[$columnName]['bind'];
    }

    $recordsToInsert = [];
    $datesToReplace = [];
    $skippedInvalidDate = 0;
    $skippedInvalidDateRows = [];

    for ($rowIndex = 1; $rowIndex < count($rows); $rowIndex++) {
        $row = is_array($rows[$rowIndex]) ? $rows[$rowIndex] : [];
        $record = [];
        $hasData = false;

        foreach ($columnDefinitions as $columnName => $definition) {
            $cellValue = $row[$columnIndexes[$columnName]] ?? null;
            $parsedValue = call_user_func($definition['parser'], $cellValue);
            $record[$columnName] = $parsedValue;
            if ($parsedValue !== null && $parsedValue !== '') {
                $hasData = true;
            }
        }

        if (!$hasData) {
            continue;
        }

        if (empty($record['fecha'])) {
            $record['fecha'] = build_comprobante_date_from_parts($record['ano'] ?? null, $record['mes'] ?? null, $record['dia'] ?? null);
        }

        if (empty($_POST['truncate']) && empty($record['fecha'])) {
            $skippedInvalidDate++;
            if (count($skippedInvalidDateRows) < 15) {
                $skippedInvalidDateRows[] = $rowIndex + 1;
            }
            continue;
        }

        $recordsToInsert[] = $record;
        if (!empty($record['fecha'])) {
            $datesToReplace[$record['fecha']] = true;
        }
    }

    if (empty($recordsToInsert)) {
        throw new Exception('El archivo no contiene filas validas para importar.');
    }

    if (empty($_POST['truncate']) && empty($datesToReplace)) {
        throw new Exception('No se pudo detectar ninguna fecha valida en el archivo para reemplazar los registros existentes.');
    }

    if (!@$mysqli->ping()) {
        throw new Exception('No se pudo establecer la conexion con MySQL para iniciar la importacion.');
    }

    $mysqli->begin_transaction();
    $deletedRows = 0;
    $insertedRows = 0;

    if (empty($_POST['truncate']) && !empty($datesToReplace)) {
        $replaceDates = array_keys($datesToReplace);
        $placeholders = implode(',', array_fill(0, count($replaceDates), '?'));
        $deleteSql = 'DELETE FROM `comprobantes_detallados` WHERE `fecha` IN (' . $placeholders . ')';
        $deleteStmt = $mysqli->prepare($deleteSql);
        if (!$deleteStmt) {
            throw new Exception('Error al preparar el reemplazo por fecha: ' . $mysqli->error);
        }

        $deleteTypes = str_repeat('s', count($replaceDates));
        if (!bind_stmt_params($deleteStmt, $deleteTypes, $replaceDates)) {
            $deleteStmt->close();
            throw new Exception('No se pudieron enlazar las fechas para el reemplazo.');
        }
        if (!$deleteStmt->execute()) {
            $deleteStmt->close();
            throw new Exception('Error al eliminar registros existentes de las fechas detectadas: ' . $deleteStmt->error);
        }

        $deletedRows = $deleteStmt->affected_rows;
        $deleteStmt->close();
    }

    foreach ($recordsToInsert as $record) {
        $params = [];
        foreach ($columnsInOrder as $columnName) {
            $params[] = $record[$columnName];
        }

        if (!bind_stmt_params($stmt, $bindTypes, $params)) {
            $mysqli->rollback();
            throw new Exception('No se pudieron enlazar los valores para la insercion.');
        }

        if (!$stmt->execute()) {
            $mysqli->rollback();
            throw new Exception('Error al insertar una fila: ' . $stmt->error);
        }

        $insertedRows++;
    }

    $persistedRows = 0;
    if (!empty($datesToReplace)) {
        $replaceDates = array_keys($datesToReplace);
        $placeholders = implode(',', array_fill(0, count($replaceDates), '?'));
        $countSql = 'SELECT COUNT(*) AS total FROM `comprobantes_detallados` WHERE `fecha` IN (' . $placeholders . ')';
        $countStmt = $mysqli->prepare($countSql);
        if (!$countStmt) {
            throw new Exception('Error al validar filas persistidas: ' . $mysqli->error);
        }

        $countTypes = str_repeat('s', count($replaceDates));
        if (!bind_stmt_params($countStmt, $countTypes, $replaceDates)) {
            $countStmt->close();
            throw new Exception('No se pudieron enlazar las fechas para el conteo final.');
        }
        if (!$countStmt->execute()) {
            $countStmt->close();
            throw new Exception('Error al contar filas persistidas: ' . $countStmt->error);
        }

        $countResult = $countStmt->get_result();
        $persistedRows = (int)(($countResult && ($rowCount = $countResult->fetch_assoc())) ? ($rowCount['total'] ?? 0) : 0);
        $countStmt->close();
    }

    $mysqli->commit();
    $stmt->close();
    $mysqli->close();

    $message = 'Se importaron ' . (int)$insertedRows . ' filas a comprobantes_detallados.';
    if (empty($_POST['truncate']) && !empty($datesToReplace)) {
        $message .= ' Se reemplazaron los registros existentes de ' . count($datesToReplace) . ' fecha(s) y se eliminaron ' . (int)$deletedRows . ' fila(s) previas.';
    }
    if ($skippedInvalidDate > 0) {
        $message .= ' Se omitieron ' . (int)$skippedInvalidDate . ' fila(s) sin fecha valida.';
    }
    if (!empty($datesToReplace)) {
        $message .= ' Filas persistidas para las fechas detectadas: ' . (int)$persistedRows . '.';
    }

    respondImportComprobantes(true, $message, 200, [
        'rows' => (int)$insertedRows,
        'deleted_rows' => (int)$deletedRows,
        'replaced_dates' => array_keys($datesToReplace),
        'persisted_rows' => (int)$persistedRows,
        'skipped_invalid_date_rows' => (int)$skippedInvalidDate,
        'skipped_invalid_date_row_numbers' => $skippedInvalidDateRows
    ]);
} catch (Throwable $e) {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        try {
            @$mysqli->rollback();
        } catch (Throwable $rollbackError) {
        }
    }
    respondImportComprobantes(false, 'Error al procesar comprobantes detallados: ' . $e->getMessage(), 500);
}