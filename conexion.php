<?php
// conexion.php
$mysqli = new mysqli('sql306.infinityfree.com', 'if0_39093659', '923486317', 'if0_39093659_c_pedidos');
if ($mysqli->connect_errno) {
    die('Error de conexión a la base de datos: ' . $mysqli->connect_error);
}
?>
