<?php
// conexion.php
// Zona horaria de la app (Perú)
@date_default_timezone_set('America/Lima');

$mysqli = new mysqli('sql306.infinityfree.com', 'if0_39093659', '923486317', 'if0_39093659_c_pedidos');
if ($mysqli->connect_errno) {
    die('Error de conexión a la base de datos: ' . $mysqli->connect_error);
}
// Charset y zona horaria de la sesión MySQL
@$mysqli->set_charset('utf8mb4');
// Alinear NOW(), TIMESTAMPDIFF, etc. a -05:00 (sin DST)
@$mysqli->query("SET time_zone = '-05:00'");
?>
