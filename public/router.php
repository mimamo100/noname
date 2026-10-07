<?php
// Router for PHP's built-in web server, for local testing only. It mirrors .htaccess:
//   php -S localhost:8000 -t public public/router.php
// On Apache (cPanel) this file is never used.

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/api/')) {
    require __DIR__ . '/api.php';
    return true;
}
if ($path === '/' || str_starts_with($path, '/w/')) {
    readfile(__DIR__ . '/index.html');
    return true;
}
return false; // Serve static files as-is.
