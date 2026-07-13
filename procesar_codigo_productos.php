<?php
require_once __DIR__ . '/require_login.php';
require_once __DIR__ . '/init.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/conexion.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$isAjax = (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest')
    || (stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false);

function respondImportProductos(bool $ok, string $message, int $statusCode = 200, array $extra = []): void {
    global $isAjax;
    if ($isAjax) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($ok) {
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Importacion de Productos</title><link rel="stylesheet" href="' . htmlspecialchars(asset_url('estilos.css'), ENT_QUOTES, 'UTF-8') . '"></head><body><div class="container">';
        echo '<h2>Productos importados correctamente</h2>';
        echo '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
        echo '<a href="index.php">Volver</a>';
        echo '</div></body></html>';
        exit;
    }

    http_response_code($statusCode);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Error</title><link rel="stylesheet" href="' . htmlspecialchars(asset_url('estilos.css'), ENT_QUOTES, 'UTF-8') . '"></head><body><div class="container">';
    echo '<h2>Error al importar productos</h2>';
    echo '<pre style="white-space:pre-wrap;background:#f8f8f8;padding:12px;border:1px solid #ddd;border-radius:6px;">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</pre>';
    echo '<a href="index.php">Volver</a>';
    echo '</div></body></html>';
    exit;
}

function normalize_product_header($value): string {
    $value = trim((string)$value);
    $map = [
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
    ];
    $value = strtr($value, $map);
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '', $value);
    return $value;
}

function get_table_columns(mysqli $mysqli, string $table): array {
    $columns = [];
    if ($res = $mysqli->query("SHOW COLUMNS FROM `{$table}`")) {
        while ($row = $res->fetch_assoc()) {
            $columns[strtolower((string)($row['Field'] ?? ''))] = true;
        }
        $res->close();
    }
    return $columns;
}

function parse_product_text($value): ?string {
    if ($value === null) {
        return null;
    }
    $text = trim((string)$value);
    return $text === '' ? null : $text;
}

function parse_product_decimal($value): ?float {
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
    return is_numeric($raw) ? (float)$raw : null;
}

function parse_product_bool($value): ?int {
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['archivo_codigo_productos'])) {
    if ($isAjax) {
        respondImportProductos(false, 'Solicitud invalida para importacion.', 405);
    }
    header('Location: index.php');
    exit;
}

try {
    $archivoTmp = $_FILES['archivo_codigo_productos']['tmp_name'] ?? '';
    if (!is_uploaded_file($archivoTmp)) {
        respondImportProductos(false, 'No se recibio un archivo valido.', 400);
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

    $createSql = "CREATE TABLE IF NOT EXISTS `codigo_productos` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `codigo` varchar(50) DEFAULT NULL,
      `producto` varchar(255) DEFAULT NULL,
      `codigofabrica` varchar(50) DEFAULT NULL,
      `unidad_ref` varchar(50) DEFAULT NULL,
      `stock_fisico` varchar(50) DEFAULT NULL,
      `stock_x_entregar` varchar(50) DEFAULT NULL,
      `stock_x_recibir` varchar(50) DEFAULT NULL,
      `linea` varchar(150) DEFAULT NULL,
      `marca` varchar(100) DEFAULT NULL,
      `activo` tinyint(1) DEFAULT NULL,
      `cod_sunat` varchar(50) DEFAULT NULL,
      `cps` decimal(15,2) DEFAULT NULL,
      `cucs` decimal(15,2) DEFAULT NULL,
    `codigose` varchar(100) DEFAULT NULL,
      `esproducto` tinyint(1) DEFAULT NULL,
    `espercepcion` tinyint(1) DEFAULT NULL,
      `proveedor` varchar(255) DEFAULT NULL,
      `dpercepcion` varchar(100) DEFAULT NULL,
      `esdetraccion` tinyint(1) DEFAULT NULL,
      `detrencion` varchar(100) DEFAULT NULL,
      `uni_ref` varchar(50) DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `idx_codigo_productos_codigo` (`codigo`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$mysqli->query($createSql)) {
        throw new Exception('No se pudo asegurar la tabla codigo_productos: ' . $mysqli->error);
    }

    $columnDefinitions = [
        'codigo' => ['aliases' => ['codigo', 'codigoproducto', 'cod_producto'], 'parser' => 'parse_product_text', 'required' => true],
        'producto' => ['aliases' => ['producto', 'nombreproducto', 'descripcion', 'descripcionproducto'], 'parser' => 'parse_product_text', 'required' => true],
        'codigofabrica' => ['aliases' => ['codigofabrica', 'codigo_fabrica', 'codfabrica'], 'parser' => 'parse_product_text'],
        'unidad_ref' => ['aliases' => ['unidadref', 'unidad_ref'], 'parser' => 'parse_product_text'],
        'stock_fisico' => ['aliases' => ['stockfisico', 'stock_fisico'], 'parser' => 'parse_product_text'],
        'stock_x_entregar' => ['aliases' => ['stockxentregar', 'stock_x_entregar'], 'parser' => 'parse_product_text'],
        'stock_x_recibir' => ['aliases' => ['stockxrecibir', 'stock_x_recibir'], 'parser' => 'parse_product_text'],
        'linea' => ['aliases' => ['linea'], 'parser' => 'parse_product_text'],
        'marca' => ['aliases' => ['marca'], 'parser' => 'parse_product_text'],
        'activo' => ['aliases' => ['activo'], 'parser' => 'parse_product_bool'],
        'cod_sunat' => ['aliases' => ['codsunat', 'cod_sunat'], 'parser' => 'parse_product_text'],
        'cps' => ['aliases' => ['cps'], 'parser' => 'parse_product_decimal'],
        'cucs' => ['aliases' => ['cucs'], 'parser' => 'parse_product_decimal'],
        'codigose' => ['aliases' => ['codigose', 'escodigose'], 'parser' => 'parse_product_text'],
        'esproducto' => ['aliases' => ['esproducto'], 'parser' => 'parse_product_bool'],
        'espercepcion' => ['aliases' => ['espercepcion', 'esperecepcion'], 'parser' => 'parse_product_bool'],
        'proveedor' => ['aliases' => ['proveedor'], 'parser' => 'parse_product_text'],
        'dpercepcion' => ['aliases' => ['dpercepcion'], 'parser' => 'parse_product_text'],
        'esdetraccion' => ['aliases' => ['esdetraccion'], 'parser' => 'parse_product_bool'],
        'detrencion' => ['aliases' => ['detrencion', 'detraccion'], 'parser' => 'parse_product_text'],
        'uni_ref' => ['aliases' => ['uniref', 'uni_ref'], 'parser' => 'parse_product_text'],
    ];

    $headers = $rows[0];
    $headerMap = [];
    foreach ($headers as $index => $header) {
        $normalized = normalize_product_header($header);
        if ($normalized !== '') {
            $headerMap[$normalized] = $index;
        }
    }

    $columnIndexes = [];
    $missingRequired = [];
    foreach ($columnDefinitions as $columnName => $definition) {
        $foundIndex = null;
        foreach ($definition['aliases'] as $alias) {
            $normalizedAlias = normalize_product_header($alias);
            if (array_key_exists($normalizedAlias, $headerMap)) {
                $foundIndex = $headerMap[$normalizedAlias];
                break;
            }
        }
        if ($foundIndex === null && !empty($definition['required'])) {
            $missingRequired[] = $columnName;
            continue;
        }
        $columnIndexes[$columnName] = $foundIndex;
    }

    if ($missingRequired) {
        throw new Exception('Faltan columnas requeridas en el Excel: ' . implode(', ', $missingRequired));
    }

    if (!empty($_POST['truncate'])) {
        if (!$mysqli->query('TRUNCATE TABLE `codigo_productos`')) {
            throw new Exception('No se pudo vaciar la tabla antes de importar: ' . $mysqli->error);
        }
    }

    $tableColumns = get_table_columns($mysqli, 'codigo_productos');
    $columnAliasesToDb = [
        'codigose' => isset($tableColumns['codigose']) ? 'codigose' : (isset($tableColumns['escodigose']) ? 'escodigose' : null),
        'espercepcion' => isset($tableColumns['espercepcion']) ? 'espercepcion' : (isset($tableColumns['esperecepcion']) ? 'esperecepcion' : null),
    ];
    $columnBindTypes = [
        'codigo' => 's',
        'producto' => 's',
        'codigofabrica' => 's',
        'unidad_ref' => 's',
        'stock_fisico' => 's',
        'stock_x_entregar' => 's',
        'stock_x_recibir' => 's',
        'linea' => 's',
        'marca' => 's',
        'activo' => 'i',
        'cod_sunat' => 's',
        'cps' => 'd',
        'cucs' => 'd',
        'codigose' => 's',
        'esproducto' => 'i',
        'espercepcion' => 'i',
        'proveedor' => 's',
        'dpercepcion' => 's',
        'esdetraccion' => 'i',
        'detrencion' => 's',
        'uni_ref' => 's',
    ];
    $dbColumnsByDefinition = [];
    foreach (array_keys($columnDefinitions) as $definitionColumn) {
        $dbColumn = $columnAliasesToDb[$definitionColumn] ?? $definitionColumn;
        if ($dbColumn !== null && isset($tableColumns[$dbColumn])) {
            $dbColumnsByDefinition[$definitionColumn] = $dbColumn;
        }
    }
    if (!isset($dbColumnsByDefinition['codigo']) || !isset($dbColumnsByDefinition['producto'])) {
        throw new Exception('La tabla codigo_productos no tiene las columnas minimas requeridas (codigo, producto).');
    }

    $updatableDefinitionColumns = array_values(array_filter(array_keys($dbColumnsByDefinition), function($column) {
        return $column !== 'codigo';
    }));
    $updateAssignments = array_map(function($column) use ($dbColumnsByDefinition) {
        return $dbColumnsByDefinition[$column] . '=?';
    }, $updatableDefinitionColumns);
    $insertDefinitionColumns = array_merge(['codigo'], $updatableDefinitionColumns);
    $insertDbColumns = array_map(function($column) use ($dbColumnsByDefinition) {
        return $dbColumnsByDefinition[$column];
    }, $insertDefinitionColumns);

    $selectStmt = $mysqli->prepare('SELECT id FROM codigo_productos WHERE codigo = ? LIMIT 1');
    $updateStmt = $mysqli->prepare('UPDATE codigo_productos SET ' . implode(', ', $updateAssignments) . ' WHERE id=?');
    $insertStmt = $mysqli->prepare('INSERT INTO codigo_productos (`' . implode('`, `', $insertDbColumns) . '`) VALUES (' . implode(',', array_fill(0, count($insertDbColumns), '?')) . ')');
    if (!$selectStmt || !$updateStmt || !$insertStmt) {
        throw new Exception('No se pudieron preparar las consultas de importacion: ' . $mysqli->error);
    }

    $inserted = 0;
    $updated = 0;
    $skipped = 0;
    foreach (array_slice($rows, 1) as $row) {
        $record = [];
        foreach ($columnDefinitions as $columnName => $definition) {
            $index = $columnIndexes[$columnName] ?? null;
            $raw = ($index !== null && array_key_exists($index, $row)) ? $row[$index] : null;
            $record[$columnName] = $definition['parser']($raw);
        }

        $codigo = trim((string)($record['codigo'] ?? ''));
        $producto = trim((string)($record['producto'] ?? ''));
        if ($codigo === '' && $producto === '') {
            continue;
        }
        if ($codigo === '') {
            $skipped++;
            continue;
        }

        $selectStmt->bind_param('s', $codigo);
        $selectStmt->execute();
        $res = $selectStmt->get_result();
        $existing = $res ? $res->fetch_assoc() : null;
        if ($res) {
            $res->close();
        }

        if ($existing) {
            $id = (int)$existing['id'];
            $updateValues = [];
            $updateTypes = '';
            foreach ($updatableDefinitionColumns as $column) {
                $updateValues[] = $record[$column] ?? null;
                $updateTypes .= $columnBindTypes[$column] ?? 's';
            }
            $updateValues[] = $id;
            $updateTypes .= 'i';
            $updateStmt->bind_param($updateTypes, ...$updateValues);
            if (!$updateStmt->execute()) {
                throw new Exception('Error al actualizar producto con codigo ' . $codigo . ': ' . $updateStmt->error);
            }
            $updated++;
            continue;
        }

        $insertValues = [];
        $insertTypes = '';
        foreach ($insertDefinitionColumns as $column) {
            $insertValues[] = $record[$column] ?? null;
            $insertTypes .= $columnBindTypes[$column] ?? 's';
        }
        $insertStmt->bind_param($insertTypes, ...$insertValues);
        if (!$insertStmt->execute()) {
            throw new Exception('Error al insertar producto con codigo ' . $codigo . ': ' . $insertStmt->error);
        }
        $inserted++;
    }

    $selectStmt->close();
    $updateStmt->close();
    $insertStmt->close();
    $mysqli->close();

    respondImportProductos(true, 'Se procesaron productos correctamente. Insertados: ' . $inserted . ', actualizados: ' . $updated . ', omitidos: ' . $skipped . '.', 200, [
        'inserted' => $inserted,
        'updated' => $updated,
        'skipped' => $skipped,
    ]);
} catch (Throwable $e) {
    respondImportProductos(false, 'Error al procesar productos: ' . $e->getMessage(), 500);
}