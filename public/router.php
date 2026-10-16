<?php

declare(strict_types=1);

// PHP's built-in server needs an explicit router so existing Vite assets are
// served as static files instead of being sent through Laravel.
$path = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

if ($path !== '/' && is_file(__DIR__.$path)) {
    return false;
}

require __DIR__.'/index.php';
