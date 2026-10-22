<?php

// php -S router for the UI tests: "/" is the app shell, real files are served as is,
// everything else goes to Symfony (nginx does the same in production).
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/../../public/index.html');
    return true;
}
if (is_string($path) && is_file(__DIR__ . '/../../public' . $path)) {
    return false;
}
require __DIR__ . '/../../public/index.php';
