<?php
// init.php: Encabezados para desactivar caché y helpers de versionado de assets.
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

function asset_version(string $path): string {
    $cleanPath = parse_url($path, PHP_URL_PATH);
    if (!is_string($cleanPath) || $cleanPath === '') {
        return (string)time();
    }

    $fullPath = __DIR__ . '/' . ltrim(str_replace('\\', '/', $cleanPath), '/');
    if (is_file($fullPath)) {
        $mtime = @filemtime($fullPath);
        if ($mtime !== false) {
            return (string)$mtime;
        }
    }

    return (string)time();
}

function asset_url(string $path): string {
    if ($path === '') {
        return $path;
    }

    if (preg_match('#^(?:https?:)?//#i', $path) || strpos($path, 'data:') === 0) {
        return $path;
    }

    $separator = (strpos($path, '?') === false) ? '?' : '&';
    return $path . $separator . 'v=' . rawurlencode(asset_version($path));
}

function versionize_html_assets(string $content): string {
    return preg_replace_callback(
        '#((?:href|src)=["\'])([^"\']+\.(?:css|js|png|jpg|jpeg|gif|svg|webp))((?:\?[^"\']*)?)(["\'])#i',
        function (array $matches): string {
            $prefix = $matches[1];
            $path = $matches[2];
            $suffixQuote = $matches[4];

            if (preg_match('#^(?:https?:)?//#i', $path) || strpos($path, 'data:') === 0) {
                return $matches[0];
            }

            return $prefix . asset_url($path) . $suffixQuote;
        },
        $content
    );
}

$assetVersion = asset_version('estilos.css');
?>
