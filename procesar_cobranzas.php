<?php
require_once __DIR__ . '/require_login.php';
require_once __DIR__ . '/init.php';
// Mostrar errores para depuración
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'vendor/autoload.php'; // PhpSpreadsheet
require_once 'conexion.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsDate;

function normalizar_col($nombre) {
    $nombre = trim((string)$nombre);
    // Reemplazar acentos/ñ
    $mapa = [
        'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N',
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n'
    ];
    $nombre = strtr($nombre, $mapa);
    // No alfanumérico -> _
    $nombre = preg_replace('/[^A-Za-z0-9_]+/', '_', $nombre);
    // Compactar y recortar _
    $nombre = preg_replace('/_+/', '_', $nombre);
    $nombre = trim($nombre, '_');
    if ($nombre === '') $nombre = 'col';
    if (preg_match('/^\d/', $nombre)) $nombre = 'col_' . $nombre;
    return strtolower($nombre);
}

function parse_date_cell($value) {
    if ($value === null || $value === '') return null;
    // Si es numérico tipo Excel serial
    if (is_numeric($value) && $value > 20000 && $value < 60000) { // rango aproximado
        try {
            $dt = XlsDate::excelToDateTimeObject((float)$value);
            return $dt->format('Y-m-d');
        } catch (Exception $e) { /* fall through */ }
    }
    // Si es string con fecha
    $v = trim((string)$value);
    // Intentar DD/MM/YYYY o MM/DD/YYYY
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $v, $m)) {
        $d = str_pad($m[1], 2, '0', STR_PAD_LEFT);
        $M = str_pad($m[2], 2, '0', STR_PAD_LEFT);
        $Y = $m[3];
        // Asumimos formato día/mes/año
        return "$Y-$M-$d";
    }
    // Intentar YYYY-MM-DD
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
        return $v;
    }
    // Como fallback, intentar con DateTime
    try {
        $dt = new DateTime($v);
        return $dt->format('Y-m-d');
    } catch (Exception $e) {
        return null;
    }
}

function sanitize_decimal($value) {
    if ($value === null || $value === '') return null;
    // quitar separadores de miles comunes
    $v = str_replace([' ', ',', "\u{00A0}"], ['', '', ''], (string)$value);
    // permitir solo números, signo y punto
    $v = preg_replace('/[^0-9.\-]/', '', $v);
    if ($v === '' || $v === '-' || $v === '.') return null;
    return $v; // dejar como string; MySQL lo convertirá a DECIMAL
}

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
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Importacion de Cobranzas</title><link rel="stylesheet" href="' . htmlspecialchars(asset_url('estilos.css'), ENT_QUOTES, 'UTF-8') . '"></head><body><div class="container">';
        echo '<h2>!Cobranzas importadas correctamente!</h2>';
        echo '<p>' . htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') . '</p>';
        echo '<a href="index.php">Volver</a>';
        echo '</div></body></html>';
        exit;
    }

    http_response_code((int)$statusCode);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Error</title><link rel="stylesheet" href="' . htmlspecialchars(asset_url('estilos.css'), ENT_QUOTES, 'UTF-8') . '"></head><body><div class="container">';
    echo '<h2>Error al importar cobranzas</h2>';
    echo '<pre style="white-space:pre-wrap;background:#f8f8f8;padding:12px;border:1px solid #ddd;border-radius:6px;">' . htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') . '</pre>';
    echo '<a href="index.php">Volver</a>';
    echo '</div></body></html>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['archivo_cobranzas'])) {
    if ($isAjax) {
        respondImport(false, 'Solicitud invalida para importacion.', 405);
    }
    header('Location: index.php');
    exit();
}

try {
    $archivoTmp = $_FILES['archivo_cobranzas']['tmp_name'] ?? '';

    if (!is_uploaded_file($archivoTmp)) {
        throw new Exception('No se recibio un archivo valido.');
    }

    $spreadsheet = IOFactory::load($archivoTmp);

    $sheet = $spreadsheet->getActiveSheet();
    // Obtener arreglo con datos formateados (fechas como se ven)
    $datos = $sheet->toArray(null, true, true, false);
    if (!$datos || count($datos) < 2) {
        throw new Exception('El archivo no contiene datos suficientes (se necesita al menos encabezado y una fila).');
    }

    // Encabezados y columnas normalizadas
    $headers = $datos[0];
    $colsNorm = array_map('normalizar_col', $headers);

    // Lista de columnas destino (misma que encabezados normalizados)
    $tabla = 'cuentas_por_cobrar_pagar';

    // Opcional: vaciar tabla
    if (!empty($_POST['truncate'])) {
        $mysqli->query("TRUNCATE TABLE `$tabla`");
    }

    // Preparacion del INSERT dinamico
    $placeholders = implode(',', array_fill(0, count($colsNorm), '?'));
    $colList = '`' . implode('`,`', $colsNorm) . '`';
    $sql = "INSERT INTO `$tabla` ($colList) VALUES ($placeholders)";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new Exception('Error en la preparacion de la consulta: ' . $mysqli->error);
    }

    // Identificar columnas por nombre para conversion especifica
    $lowerCols = array_map('strval', $colsNorm);
    $isDateCol = array_map(function($c){ return strpos($c, 'fecha') !== false; }, $lowerCols);

    $moneyCols = [
        'documentopagomontosoles',
        'documentopagomontodolares',
        'documentopagosaldosoles',
        'documentopagosaldodolares',
        'documentomontopercepcionsoles',
    ];
    $isMoneyCol = array_map(function($c) use ($moneyCols){ return in_array($c, $moneyCols, true); }, $lowerCols);

    // Recorrer filas de datos
    $insertadas = 0;
    for ($i = 1; $i < count($datos); $i++) {
        $fila = $datos[$i];
        if (!is_array($fila)) continue;
        // pad a numero de columnas
        $fila = array_pad($fila, count($colsNorm), null);

        $vals = [];
        foreach ($fila as $idx => $val) {
            $v = $val;

            if ($isDateCol[$idx]) {
                $v = parse_date_cell($val);
            } elseif ($isMoneyCol[$idx]) {
                $v = sanitize_decimal($val);
            } else {
                // Recortar espacios para columnas de texto.
                if (is_string($v)) $v = trim($v);
            }
            $vals[] = $v;
        }

        // Tipos para bind: todos como string para simplificar; MySQL castea
        $types = str_repeat('s', count($vals));
        $stmt->bind_param($types, ...$vals);
        $ok = $stmt->execute();
        if (!$ok) {
            throw new Exception('Error al insertar fila ' . ($i + 1) . ': ' . $stmt->error);
        }
        $insertadas++;
    }

    $stmt->close();
    $mysqli->close();

    respondImport(true, 'Total filas insertadas: ' . intval($insertadas), 200, ['rows' => intval($insertadas)]);
} catch (Throwable $e) {
    respondImport(false, 'Error al procesar cobranzas: ' . $e->getMessage(), 500);
}

header('Location: index.php');
exit();
