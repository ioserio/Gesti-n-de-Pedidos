<?php
// conexion.php
// Zona horaria de la app (Perú)
date_default_timezone_set('America/Lima');

$dbHost = getenv('DB_HOST') ?: 'HOST-NAME';
$dbUser = getenv('DB_USER') ?: 'NAME-USER-DATABASE';
$dbPass = getenv('DB_PASS') ?: 'PASSWORD-DATABASE';
$dbName = getenv('DB_NAME') ?: 'NAME-DB';
$dbPort = (int)(getenv('DB_PORT') ?: 3306);

// Evita excepciones no controladas de mysqli que terminan en HTTP 500.
mysqli_report(MYSQLI_REPORT_OFF);

try {
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
} catch (Throwable $e) {
    error_log('DB exception: ' . $e->getMessage());
    http_response_code(500);
    die('Error de conexión a la base de datos.');
}

if ($mysqli->connect_errno) {
    error_log('DB connect error [' . $mysqli->connect_errno . ']: ' . $mysqli->connect_error);
    http_response_code(500);
    die('Error de conexión a la base de datos.');
}
// Charset y zona horaria de la sesión MySQL
if (!$mysqli->set_charset('utf8mb4')) {
    error_log('DB charset error: ' . $mysqli->error);
}
// Alinear NOW(), TIMESTAMPDIFF, etc. a -05:00 (sin DST)
if (!$mysqli->query("SET time_zone = '-05:00'")) {
    error_log('DB time_zone error: ' . $mysqli->error);
}
?>
