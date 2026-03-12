<?php
require_once __DIR__ . '/require_login.php';
// Mostrar errores para depuración en el hosting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require 'vendor/autoload.php'; // Incluye PhpSpreadsheet
use PhpOffice\PhpSpreadsheet\IOFactory;

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
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Importacion exitosa</title><link rel="stylesheet" href="estilos.css"></head><body><div class="container">';
        echo '<h2>!Datos importados correctamente a la base de datos!</h2>';
        echo '<p>' . htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') . '</p>';
        echo '<a href="index.php">Volver</a>';
        echo '</div></body></html>';
        exit;
    }

    http_response_code((int)$statusCode);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Error</title><link rel="stylesheet" href="estilos.css"></head><body><div class="container">';
    echo '<h2>Error al importar pedidos</h2>';
    echo '<pre style="white-space:pre-wrap;background:#f8f8f8;padding:12px;border:1px solid #ddd;border-radius:6px;">' . htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') . '</pre>';
    echo '<a href="index.php">Volver</a>';
    echo '</div></body></html>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['archivo_excel'])) {
    if ($isAjax) {
        respondImport(false, 'Solicitud invalida para importacion.', 405);
    }
    header('Location: index.php');
    exit();
}

try {
    $archivoTmp = $_FILES['archivo_excel']['tmp_name'] ?? '';
    if (!is_uploaded_file($archivoTmp)) {
        respondImport(false, 'No se recibio un archivo valido.', 400);
    }

    $spreadsheet = IOFactory::load($archivoTmp);
    $hoja = $spreadsheet->getActiveSheet();
    $datos = $hoja->toArray();

    if (!$datos || count($datos) < 2) {
        respondImport(false, 'El archivo no contiene filas de datos para importar.', 400);
    }

    // Conexión a la base de datos
    require_once 'conexion.php';

    // Obtener los nombres de las columnas
    $columnas = $datos[0];



    // Detectar la fecha del archivo Excel (primera fila de datos)
    $fecha_excel = null;
    for ($i = 1; $i < count($datos); $i++) {
        $fila = $datos[$i];
        if (isset($fila[2]) && !empty($fila[2])) {
            // Convertir la fecha si viene como DD/MM/YYYY o MM/DD/YYYY
            if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $fila[2], $matches)) {
                $dia = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                $mes = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
                $anio = $matches[3];
                $fecha_excel = "$anio-$mes-$dia";
            } else {
                $fecha_excel = $fila[2];
            }
            break;
        }
    }

    // Eliminar todos los pedidos de la fecha detectada
    if ($fecha_excel) {
        $mysqli->query("DELETE FROM pedidos_x_dia WHERE Fecha = '" . $mysqli->real_escape_string($fecha_excel) . "'");
    }

    // Preparar la consulta de inserción/actualización
    $sql = "INSERT INTO pedidos_x_dia (NumCp, Hora, Fecha, Cod_Vendedor, Nom_Vendedor, Codigo, Nombre, Total_IGV, Zona, Anulado, FFVV) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) " .
        "ON DUPLICATE KEY UPDATE Hora=VALUES(Hora), Fecha=VALUES(Fecha), Cod_Vendedor=VALUES(Cod_Vendedor), Nom_Vendedor=VALUES(Nom_Vendedor), Codigo=VALUES(Codigo), Nombre=VALUES(Nombre), Total_IGV=VALUES(Total_IGV), Zona=VALUES(Zona), Anulado=VALUES(Anulado), FFVV=VALUES(FFVV)";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new Exception('Error en la preparacion de la consulta: ' . $mysqli->error);
    }

    // Insertar o actualizar cada fila (excepto la cabecera)
    $procesadas = 0;
    for ($i = 1; $i < count($datos); $i++) {
        $fila = $datos[$i];
        $fila = array_pad($fila, 11, null);
        if (isset($fila[7])) {
            $fila[7] = str_replace(',', '', $fila[7]);
        }
        if (isset($fila[2]) && preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $fila[2], $matches)) {
            $dia = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $mes = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $anio = $matches[3];
            $fila[2] = "$anio-$mes-$dia";
        }
        $stmt->bind_param('sssssssssss',
            $fila[0], $fila[1], $fila[2], $fila[3], $fila[4],
            $fila[5], $fila[6], $fila[7], $fila[8], $fila[9], $fila[10]
        );
        if (!$stmt->execute()) {
            throw new Exception('Error al guardar la fila ' . ($i + 1) . ': ' . $stmt->error);
        }
        $procesadas++;
    }
    $stmt->close();
    $mysqli->close();

    respondImport(true, 'Se procesaron ' . (int)$procesadas . ' filas correctamente.', 200, ['rows' => (int)$procesadas]);
} catch (Throwable $e) {
    respondImport(false, 'Error al procesar el archivo: ' . $e->getMessage(), 500);
}
?>
