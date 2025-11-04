<?php
// require_login.php
// Incluir en cada módulo PHP que quieras proteger
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$logged = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
if ($logged) {
    return; // autenticado
}

// Detectar solicitudes AJAX / API
$isAjax = false;
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    $isAjax = true;
}
$accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
if (strpos($accept, 'application/json') !== false) {
    $isAjax = true;
}

if ($isAjax) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok'=>false,'error'=>'UNAUTHORIZED','redirect'=>'login.php']);
    exit;
}

// Redirigir a login preservando la URL objetivo
$next = $_SERVER['REQUEST_URI'] ?? 'index.php';
header('Location: login.php?next=' . urlencode($next));
exit;
