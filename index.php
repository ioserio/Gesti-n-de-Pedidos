<?php
require_once __DIR__ . '/require_login.php';
require_once __DIR__ . '/init.php';

// Cargar el contenido de index.html y aplicar versionado dinámico a CSS y JS
$content = @file_get_contents(__DIR__ . '/index.html');
if ($content === false) {
	http_response_code(500);
	echo 'Error cargando interfaz.';
	exit;
}
// Reemplazar versión fija del CSS si existe y añadir versión al script principal
if (isset($assetVersion)) {
	$content = preg_replace('#estilos\.css\?v=[^"\']+#', 'estilos.css?v=' . $assetVersion, $content);
	// Agregar versión a script.js si aún no tiene query
	$content = preg_replace('#script\.js(\")#', 'script.js?v=' . $assetVersion . '$1', $content);
	$content = preg_replace('#script\.js(\')#', 'script.js?v=' . $assetVersion . '$1', $content);
	// Si no coincidió (por comillas simples/dobles), hacer reemplazo genérico sin romper otras rutas
	if (strpos($content, 'script.js?v=') === false) {
		$content = str_replace('script.js</', 'script.js?v=' . $assetVersion . '</', $content);
	}
}
echo $content;
