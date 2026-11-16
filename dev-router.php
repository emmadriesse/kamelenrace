<?php
// Alleen voor lokaal testen:
//   KAMEEL_DATA_DIR=data php -S 127.0.0.1:8080 -t public dev-router.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
if (strpos($path, '/api/') !== false) {
    require __DIR__ . '/api/index.php';
    return true;
}
return false; // gewone bestanden uit public/ laten serveren
