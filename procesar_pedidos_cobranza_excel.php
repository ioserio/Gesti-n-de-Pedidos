<?php
require_once __DIR__ . '/require_login.php';
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

function respuesta_pce(bool $ok, string $message, int $status = 200): void {
    http_response_code($status);
    $ajax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
    if ($ajax) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><html lang="es"><meta charset="utf-8"><title>Importación</title><body>';
        echo '<h2>' . ($ok ? 'Importación completada' : 'Error de importación') . '</h2><p>';
        echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p><a href="index.php">Volver</a></body></html>';
    }
    exit;
}

function clave_pce($value): string {
    $value = strtr(trim((string)$value), [
        'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N',
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n'
    ]);
    return strtolower(preg_replace('/[^A-Za-z0-9]/', '', $value));
}

function fecha_pce($value, int $row, string $field): ?string {
    if ($value === null || trim((string)$value) === '') return null;
    if (is_numeric($value)) {
        try { return ExcelDate::excelToDateTimeObject((float)$value)->format('Y-m-d'); }
        catch (Throwable $e) { /* Se informa abajo. */ }
    }
    $text = trim((string)$value);
    foreach (['!d/m/Y', '!d-m-Y', '!Y-m-d', '!d/m/y'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $text);
        if ($date && $date->getLastErrors() !== false && $date->getLastErrors()['warning_count'] === 0 && $date->getLastErrors()['error_count'] === 0) {
            return $date->format('Y-m-d');
        }
        if ($date && $date->getLastErrors() === false) return $date->format('Y-m-d');
    }
    throw new RuntimeException("Fecha inválida en fila $row, columna $field.");
}

function decimal_pce($value, int $row, string $field): ?string {
    if ($value === null || trim((string)$value) === '') return null;
    if (is_int($value) || is_float($value)) return (string)$value;
    $text = str_replace(["\u{00A0}", ' '], '', trim((string)$value));
    $comma = strrpos($text, ',');
    $dot = strrpos($text, '.');
    if ($comma !== false && $dot !== false) {
        $text = $comma > $dot ? str_replace(',', '.', str_replace('.', '', $text)) : str_replace(',', '', $text);
    } elseif ($comma !== false) {
        $text = str_replace(',', '.', $text);
    }
    if (str_starts_with($text, '.')) $text = '0' . $text;
    if (str_starts_with($text, '-.')) $text = '-0' . substr($text, 1);
    if (!preg_match('/^-?\d+(?:\.\d+)?$/', $text)) {
        throw new RuntimeException("Importe inválido en fila $row, columna $field.");
    }
    return $text;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respuesta_pce(false, 'Solicitud inválida.', 405);
$upload = $_FILES['archivo_pedidos_cobranza'] ?? null;
if (!$upload || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
    || !is_uploaded_file((string)($upload['tmp_name'] ?? ''))) {
    respuesta_pce(false, 'No se recibió un archivo válido.', 400);
}
if (!in_array(strtolower(pathinfo((string)$upload['name'], PATHINFO_EXTENSION)), ['xlsx', 'xls'], true)) {
    respuesta_pce(false, 'Selecciona un archivo .xlsx o .xls.', 400);
}

$mysqli = null;
$transaction = false;
try {
    $reader = IOFactory::createReaderForFile($upload['tmp_name']);
    $reader->setReadDataOnly(false);
    $spreadsheet = $reader->load($upload['tmp_name']);
    $sheet = $spreadsheet->getActiveSheet();
    if ($sheet->getHighestDataRow() < 2) throw new RuntimeException('El Excel no contiene filas de datos.');

    // Los tres encabezados largos aparecen cortados en la captura. Se reconocen por su prefijo.
    $expected = [
        'abreviacion'=>'Abreviacion', 'numcp'=>'NumCp', 'doc_migrado'=>'DocMigrado',
        'codigo_vendedor'=>'CodigoVendedor', 'codigo_cliente'=>'CodigoCliente',
        'nombre_cliente'=>'NombreCliente', 'direccion'=>'Direccion',
        'direccion_entrega'=>'DireccionEntrega', 'credito'=>'Credito', 'saldo'=>'Saldo',
        'tipo'=>'Tipo', 'entrega'=>'Entrega', 'tipo_doc_identidad'=>'TipoDocIden',
        'nombre_vehiculo'=>'NombreVeh', 'fecha'=>'Fecha',
        'doc_cobrar_pagar'=>'DocCobrarPagar', 'placa'=>'Placa', 'placa2'=>'Placa2',
        'numero_cons'=>'NumeroCons', 'saldo_percepcion'=>'SaldoPercepcion'
    ];
    $headerMap = [];
    foreach ($sheet->getRowIterator(1, 1) as $headerRow) {
        foreach ($headerRow->getCellIterator() as $cell) {
            $name = clave_pce($cell->getValue());
            if ($name === '') continue;
            foreach ($expected as $field => $header) {
                $key = clave_pce($header);
                $matches = in_array($field, ['tipo_doc_identidad', 'nombre_vehiculo', 'numero_cons'], true)
                    ? str_starts_with($name, $key) : $name === $key;
                if ($matches) {
                    if (isset($headerMap[$field])) throw new RuntimeException("Encabezado duplicado: $header.");
                    $headerMap[$field] = ['column' => $cell->getColumn(), 'name' => (string)$cell->getValue()];
                    break;
                }
            }
        }
    }
    $missing = array_diff(array_keys($expected), array_keys($headerMap));
    if ($missing) {
        throw new RuntimeException('Faltan encabezados: ' . implode(', ', array_map(fn($key) => $expected[$key], $missing)) . '.');
    }

    $rows = [];
    $dates = [];
    foreach ($sheet->getRowIterator(2) as $excelRow) {
        $rowNumber = $excelRow->getRowIndex();
        $values = [];
        $nonempty = false;
        foreach ($expected as $field => $header) {
            $cell = $sheet->getCell($headerMap[$field]['column'] . $rowNumber);
            $raw = $cell->getValue();
            if ($raw !== null && trim((string)$raw) !== '') $nonempty = true;
            if ($field === 'fecha') {
                $values[$field] = fecha_pce($raw, $rowNumber, $header);
            } elseif (in_array($field, ['saldo', 'saldo_percepcion'], true)) {
                $values[$field] = decimal_pce($raw, $rowNumber, $header);
            } elseif ($raw === null || trim((string)$raw) === '') {
                $values[$field] = null;
            } elseif (is_bool($raw)) {
                $values[$field] = $raw ? 'VERDADERO' : 'FALSO';
            } else {
                $values[$field] = trim((string)$cell->getFormattedValue());
            }
        }
        if (!$nonempty) continue;
        if ($values['fecha'] === null) throw new RuntimeException("Falta Fecha en la fila $rowNumber.");
        $dates[$values['fecha']] = true;
        $rows[] = array_values($values);
    }
    if (!$rows) throw new RuntimeException('No hay filas con datos para importar.');

    require __DIR__ . '/conexion.php';
    $columnsResult = $mysqli->query('SHOW COLUMNS FROM `pedidos_cobranza_excel`');
    if (!$columnsResult) {
        throw new RuntimeException('No se pudo acceder a la tabla existente pedidos_cobranza_excel: ' . $mysqli->error);
    }
    $dbColumns = [];
    while ($column = $columnsResult->fetch_assoc()) {
        $dbColumns[clave_pce($column['Field'])] = $column;
    }
    $columnsResult->free();
    $dbMap = [];
    foreach ($expected as $field => $header) {
        $dbColumn = $dbColumns[clave_pce($field)] ?? $dbColumns[clave_pce($headerMap[$field]['name'])] ?? null;
        if (!$dbColumn && in_array($field, ['tipo_doc_identidad', 'nombre_vehiculo', 'numero_cons'], true)) {
            foreach ($dbColumns as $name => $candidate) {
                if (str_starts_with($name, clave_pce($header))) {
                    $dbColumn = $candidate;
                    break;
                }
            }
        }
        if (!$dbColumn) throw new RuntimeException('Falta la columna ' . $header . ' en la tabla pedidos_cobranza_excel.');
        $dbMap[$field] = $dbColumn;
    }
    if (!preg_match('/^(?:var)?char\(|^(?:tiny|medium|long)?text$/i', $dbMap['entrega']['Type'])) {
        throw new RuntimeException('La columna Entrega debe ser de texto para guardar VERDADERO/FALSO.');
    }
    if (!$mysqli->begin_transaction()) throw new RuntimeException('No se pudo iniciar la importación.');
    $transaction = true;
    if (isset($_POST['reemplazar_fechas']) && $_POST['reemplazar_fechas'] === '1') {
        $fechaColumn = str_replace('`', '``', $dbMap['fecha']['Field']);
        $delete = $mysqli->prepare('DELETE FROM `pedidos_cobranza_excel` WHERE `' . $fechaColumn . '` = ?');
        if (!$delete) throw new RuntimeException('No se pudo preparar el reemplazo: ' . $mysqli->error);
        foreach (array_keys($dates) as $date) {
            $delete->bind_param('s', $date);
            if (!$delete->execute()) throw new RuntimeException('No se pudo reemplazar la fecha ' . $date . '.');
        }
        $delete->close();
    }
    $columns = array_map(fn($column) => str_replace('`', '``', $column['Field']), array_values($dbMap));
    $sql = 'INSERT INTO `pedidos_cobranza_excel` (`' . implode('`,`', $columns) . '`) VALUES ('
        . implode(',', array_fill(0, count($columns), '?')) . ')';
    $insert = $mysqli->prepare($sql);
    if (!$insert) throw new RuntimeException('No se pudo preparar la carga: ' . $mysqli->error);
    foreach ($rows as $index => $values) {
        $insert->bind_param(str_repeat('s', count($values)), ...$values);
        if (!$insert->execute()) throw new RuntimeException('No se pudo guardar la fila ' . ($index + 2) . ': ' . $insert->error);
    }
    $insert->close();
    if (!$mysqli->commit()) throw new RuntimeException('No se pudo confirmar la importación.');
    $transaction = false;
    respuesta_pce(true, 'Pedidos y saldos importados: ' . count($rows) . ' filas.');
} catch (Throwable $e) {
    if ($transaction && $mysqli) $mysqli->rollback();
    respuesta_pce(false, $e->getMessage(), 400);
}
