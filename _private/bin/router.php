<?php

declare(strict_types=1);

// Development-only router for the PHP built-in server (the parent directory is document root).
$path = rawurldecode(parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
if ($path === '/exchange') {
    header('Location: /exchange/', true, 301);
    exit;
}
if ($path === '/exchange/' || $path === '/exchange/index.php') {
    $_SERVER['SCRIPT_NAME'] = '/exchange/index.php';
    require dirname(__DIR__, 2) . '/index.php';
    exit;
}
if (in_array($path, ['/exchange/assets/style.css', '/exchange/assets/app.js'], true)) {
    return false;
}
http_response_code(404);
header('Content-Type: text/plain; charset=UTF-8');
echo 'Not found';
