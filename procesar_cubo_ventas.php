<?php
require_once __DIR__ . '/require_login.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/conexion.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsDate;

$isAjax = (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest')
    || (stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false);

function respondImport($ok, $message, $statusCode = 200, $extra = []) {
    global $isAjax;
    if ($isAjax) {
        http_response_code((int)$statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(array_merge(['ok' => (bool)$ok, 'message' => (string)$message], $extra), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($ok) {
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Importacion de Cubo de Ventas</title><link rel="stylesheet" href="estilos.css"></head><body><div class="container">';
        echo '<h2>!Cubo de ventas importado correctamente!</h2>';
        echo '<p>' . htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') . '</p>';
        echo '<a href="index.php">Volver</a>';
        echo '</div></body></html>';
        exit;
    }

    http_response_code((int)$statusCode);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Error</title><link rel="stylesheet" href="estilos.css"></head><body><div class="container">';
    echo '<h2>Error al importar cubo de ventas</h2>';
    echo '<pre style="white-space:pre-wrap;background:#f8f8f8;padding:12px;border:1px solid #ddd;border-radius:6px;">' . htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') . '</pre>';
    echo '<a href="index.php">Volver</a>';
    echo '</div></body></html>';
    exit;
}

function normalize_header_name($value) {
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

function parse_excel_date($value) {
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

    try {
        return (new DateTime($raw))->format('Y-m-d');
    } catch (Throwable $e) {
        return null;
    }
}

function parse_nullable_int($value) {
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
    if ($normalized === '') {
        return null;
    }

    if (!is_numeric($normalized)) {
        return null;
    }

    return (int)round((float)$normalized);
}

function parse_nullable_decimal($value) {
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

function parse_nullable_bool($value) {
    if ($value === null || $value === '') {
        return null;
    }

    if (is_bool($value)) {
        return $value ? 1 : 0;
    }

    if (is_int($value) || is_float($value)) {
        return ((float)$value) != 0.0 ? 1 : 0;
    }

    $raw = strtolower(trim((string)$value));
    if ($raw === '') {
        return null;
    }

    if (in_array($raw, ['1', 'true', 't', 'si', 'sí', 'yes', 'y'], true)) {
        return 1;
    }

    if (in_array($raw, ['0', 'false', 'f', 'no', 'n'], true)) {
        return 0;
    }

    return null;
}

function parse_nullable_text($value) {
    if ($value === null) {
        return null;
    }

    $text = trim((string)$value);
    return $text === '' ? null : $text;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['archivo_cubo_ventas'])) {
    if ($isAjax) {
        respondImport(false, 'Solicitud invalida para importacion.', 405);
    }
    header('Location: index.php');
    exit();
}

try {
    $archivoTmp = $_FILES['archivo_cubo_ventas']['tmp_name'] ?? '';

    if (!is_uploaded_file($archivoTmp)) {
        throw new Exception('No se recibio un archivo valido.');
    }

    $spreadsheet = IOFactory::load($archivoTmp);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray(null, true, true, false);

    if (!$rows || count($rows) < 2) {
        throw new Exception('El archivo no contiene filas de datos para importar.');
    }

    $columnDefinitions = [
        'codigovendedor' => ['aliases' => ['codigovendedor', 'CodigoVendedor'], 'parser' => 'parse_nullable_int'],
        'nombrevendedor' => ['aliases' => ['nombrevendedor', 'NombreVendedor'], 'parser' => 'parse_nullable_text'],
        'nombregrupoventas' => ['aliases' => ['nombregrupoventas', 'NombreGrupoVentas'], 'parser' => 'parse_nullable_text'],
        'codigocliente' => ['aliases' => ['codigocliente', 'CodigoCliente'], 'parser' => 'parse_nullable_int'],
        'nombrecliente' => ['aliases' => ['nombrecliente', 'NombreCliente'], 'parser' => 'parse_nullable_text'],
        'codigozona' => ['aliases' => ['codigozona', 'CodigoZona'], 'parser' => 'parse_nullable_int'],
        'nombrezona' => ['aliases' => ['nombrezona', 'NombreZona'], 'parser' => 'parse_nullable_text'],
        'fecha' => ['aliases' => ['fecha', 'Fecha'], 'parser' => 'parse_excel_date'],
        'abreviacion' => ['aliases' => ['abreviacion', 'Abreviacion'], 'parser' => 'parse_nullable_text'],
        'numcp' => ['aliases' => ['numcp', 'NumCp'], 'parser' => 'parse_nullable_text'],
        'codigoalmacen' => ['aliases' => ['codigoalmacen', 'CodigoAlmacen'], 'parser' => 'parse_nullable_int'],
        'credito' => ['aliases' => ['credito', 'Credito'], 'parser' => 'parse_nullable_bool'],
        'anulado' => ['aliases' => ['anulado', 'Anulado'], 'parser' => 'parse_nullable_bool'],
        'tipo' => ['aliases' => ['tipo', 'Tipo'], 'parser' => 'parse_nullable_text'],
        'ano' => ['aliases' => ['ano', 'año', 'Año'], 'parser' => 'parse_nullable_int'],
        'mes' => ['aliases' => ['mes', 'Mes'], 'parser' => 'parse_nullable_int'],
        'semana' => ['aliases' => ['semana', 'Semana'], 'parser' => 'parse_nullable_int'],
        'dia' => ['aliases' => ['dia', 'Dia'], 'parser' => 'parse_nullable_int'],
        'valorventa' => ['aliases' => ['valorventa', 'ValorVenta'], 'parser' => 'parse_nullable_decimal'],
        'total' => ['aliases' => ['total', 'Total'], 'parser' => 'parse_nullable_decimal'],
        'codigovehiculo' => ['aliases' => ['codigovehiculo', 'CodigoVehiculo'], 'parser' => 'parse_nullable_int'],
        'nombrevehiculo' => ['aliases' => ['nombrevehiculo', 'NombreVehiculo'], 'parser' => 'parse_nullable_int'],
    ];

    $headers = $rows[0];
    $headerMap = [];
    foreach ($headers as $index => $header) {
        $normalized = normalize_header_name($header);
        if ($normalized !== '') {
            $headerMap[$normalized] = $index;
        }
    }

    $missing = [];
    $columnIndexes = [];
    foreach ($columnDefinitions as $columnName => $definition) {
        $foundIndex = null;
        foreach ($definition['aliases'] as $alias) {
            $normalizedAlias = normalize_header_name($alias);
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

    $createSql = "CREATE TABLE IF NOT EXISTS `cubo_de_ventas_resumen` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `codigovendedor` int(11) DEFAULT NULL,
      `nombrevendedor` varchar(255) DEFAULT NULL,
      `nombregrupoventas` varchar(255) DEFAULT NULL,
      `codigocliente` int(11) DEFAULT NULL,
      `nombrecliente` varchar(255) DEFAULT NULL,
      `codigozona` int(11) DEFAULT NULL,
      `nombrezona` varchar(255) DEFAULT NULL,
      `fecha` date DEFAULT NULL,
      `abreviacion` varchar(20) DEFAULT NULL,
      `numcp` varchar(30) DEFAULT NULL,
      `codigoalmacen` int(11) DEFAULT NULL,
      `credito` tinyint(1) DEFAULT NULL,
      `anulado` tinyint(1) DEFAULT NULL,
      `tipo` varchar(50) DEFAULT NULL,
      `ano` smallint(6) DEFAULT NULL,
      `mes` tinyint(4) DEFAULT NULL,
      `semana` tinyint(4) DEFAULT NULL,
      `dia` tinyint(4) DEFAULT NULL,
      `valorventa` decimal(15,2) DEFAULT NULL,
      `total` decimal(15,2) DEFAULT NULL,
      `codigovehiculo` int(11) DEFAULT NULL,
      `nombrevehiculo` int(11) DEFAULT NULL,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$mysqli->query($createSql)) {
        throw new Exception('No se pudo asegurar la tabla destino: ' . $mysqli->error);
    }

    if (!empty($_POST['truncate'])) {
        if (!$mysqli->query('TRUNCATE TABLE `cubo_de_ventas_resumen`')) {
            throw new Exception('No se pudo vaciar la tabla antes de importar: ' . $mysqli->error);
        }
    }

    $insertSql = 'INSERT INTO `cubo_de_ventas_resumen` (
        `codigovendedor`, `nombrevendedor`, `nombregrupoventas`, `codigocliente`, `nombrecliente`,
        `codigozona`, `nombrezona`, `fecha`, `abreviacion`, `numcp`,
        `codigoalmacen`, `credito`, `anulado`, `tipo`, `ano`,
        `mes`, `semana`, `dia`, `valorventa`, `total`,
        `codigovehiculo`, `nombrevehiculo`
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
    $stmt = $mysqli->prepare($insertSql);
    if (!$stmt) {
        throw new Exception('Error al preparar la insercion: ' . $mysqli->error);
    }

    $mysqli->begin_transaction();
    $insertedRows = 0;

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

        $stmt->bind_param(
            'issisissssiiisiiiiddii',
            $record['codigovendedor'],
            $record['nombrevendedor'],
            $record['nombregrupoventas'],
            $record['codigocliente'],
            $record['nombrecliente'],
            $record['codigozona'],
            $record['nombrezona'],
            $record['fecha'],
            $record['abreviacion'],
            $record['numcp'],
            $record['codigoalmacen'],
            $record['credito'],
            $record['anulado'],
            $record['tipo'],
            $record['ano'],
            $record['mes'],
            $record['semana'],
            $record['dia'],
            $record['valorventa'],
            $record['total'],
            $record['codigovehiculo'],
            $record['nombrevehiculo']
        );

        if (!$stmt->execute()) {
            $mysqli->rollback();
            throw new Exception('Error al insertar la fila ' . ($rowIndex + 1) . ': ' . $stmt->error);
        }

        $insertedRows++;
    }

    $mysqli->commit();
    $stmt->close();
    $mysqli->close();

    respondImport(true, 'Se importaron ' . (int)$insertedRows . ' filas a cubo_de_ventas_resumen.', 200, ['rows' => (int)$insertedRows]);
} catch (Throwable $e) {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        try {
            @$mysqli->rollback();
        } catch (Throwable $rollbackError) {
        }
    }
    respondImport(false, 'Error al procesar cubo de ventas: ' . $e->getMessage(), 500);
}
