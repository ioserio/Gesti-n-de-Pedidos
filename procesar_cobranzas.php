<?php
require_once __DIR__ . '/require_login.php';
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivo_cobranzas'])) {
    $archivoTmp = $_FILES['archivo_cobranzas']['tmp_name'];

    if (!is_uploaded_file($archivoTmp)) {
        die('No se recibió un archivo válido.');
    }

    try {
        $spreadsheet = IOFactory::load($archivoTmp);
    } catch (Throwable $e) {
        die('No se pudo leer el Excel: ' . htmlspecialchars($e->getMessage()));
    }

    $sheet = $spreadsheet->getActiveSheet();
    // Obtener arreglo con datos formateados (fechas como se ven)
    $datos = $sheet->toArray(null, true, true, false);
    if (!$datos || count($datos) < 2) {
        die('El archivo no contiene datos suficientes (se necesita al menos encabezado y una fila).');
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

    // Preparación del INSERT dinámico
    $placeholders = implode(',', array_fill(0, count($colsNorm), '?'));
    $colList = '`' . implode('`,`', $colsNorm) . '`';
    $sql = "INSERT INTO `$tabla` ($colList) VALUES ($placeholders)";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        die('Error en la preparación de la consulta: ' . $mysqli->error);
    }

    // Identificar columnas por nombre para conversión específica
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
        // pad a número de columnas
        $fila = array_pad($fila, count($colsNorm), null);

        $vals = [];
        foreach ($fila as $idx => $val) {
            $colNorm = $colsNorm[$idx];
            $v = $val;

            if ($isDateCol[$idx]) {
                $v = parse_date_cell($val);
            } elseif ($isMoneyCol[$idx]) {
                $v = sanitize_decimal($val);
            } else {
                // recortar espacios
                if (is_string($v)) $v = trim($v);
            }
            $vals[] = $v;
        }

        // Tipos para bind: todos como string para simplificar; MySQL castea
        $types = str_repeat('s', count($vals));
        $stmt->bind_param($types, ...$vals);
        $ok = $stmt->execute();
        if (!$ok) {
            // Puedes registrar el error y seguir, o abortar
            die('Error al insertar fila ' . ($i+1) . ': ' . $stmt->error);
        }
        $insertadas++;
    }

    $stmt->close();
    $mysqli->close();

    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Importación de Cobranzas</title><link rel="stylesheet" href="estilos.css"></head><body><div class="container">';
    echo '<h2>¡Cobranzas importadas correctamente!</h2>';
    echo '<p>Total filas insertadas: <b>' . intval($insertadas) . '</b></p>';
    echo '<a href="index.html">Volver</a>';
    echo '</div></body></html>';
    exit;
}

header('Location: index.html');
exit();
