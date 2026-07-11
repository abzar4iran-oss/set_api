<?php
// Router for PHP built-in server: php -S 0.0.0.0:8080 -t public public/router.php
if (php_sapi_name() === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $file = __DIR__ . $path;
    if ($path !== '/' && is_file($file)) {
        return false;
    }
}
require __DIR__ . '/index.php';
