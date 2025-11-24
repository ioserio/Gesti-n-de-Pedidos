<?php
// init.php: Encabezados para desactivar caché y versionado de assets
// Incluir al inicio de cada script PHP que entregue contenido.
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

// Anti-caché para navegadores y proxies intermedios
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
// Compatibilidad adicional (IE antiguas)
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

// Versionado simple basado en timestamp actual
$assetVersion = time();
?>
