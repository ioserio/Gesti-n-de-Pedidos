<?php
require_once __DIR__ . '/require_login.php';
// Mostrar errores para depuración en el hosting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require 'vendor/autoload.php'; // Incluye PhpSpreadsheet
use PhpOffice\PhpSpreadsheet\IOFactory;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivo_excel'])) {
    $archivoTmp = $_FILES['archivo_excel']['tmp_name'];
    $spreadsheet = IOFactory::load($archivoTmp);
    $hoja = $spreadsheet->getActiveSheet();
    $datos = $hoja->toArray();

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
        die('Error en la preparación de la consulta: ' . $mysqli->error);
    }

    // Insertar o actualizar cada fila (excepto la cabecera)
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
        $stmt->execute();
    }
    $stmt->close();
    $mysqli->close();

    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Importación exitosa</title><link rel="stylesheet" href="estilos.css"></head><body><div class="container">';
    echo '<h2>¡Datos importados correctamente a la base de datos!</h2>';
    echo '<a href="index.html">Volver</a>';
    echo '</div></body></html>';
} else {
    header('Location: index.html');
    exit();
}
?>
