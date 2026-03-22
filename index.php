<?php
require_once __DIR__ . '/require_login.php';
require_once __DIR__ . '/init.php';

// Cargar el contenido de index.html y aplicar versionado dinámico a assets locales
$content = @file_get_contents(__DIR__ . '/index.html');
if ($content === false) {
	http_response_code(500);
	echo 'Error cargando interfaz.';
	exit;
}

$content = versionize_html_assets($content);
echo $content;
