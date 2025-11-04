<?php
require_once __DIR__ . '/require_login.php';
?>
<?php
// Servimos el mismo contenido de index.html, pero protegido
readfile(__DIR__ . '/index.html');
