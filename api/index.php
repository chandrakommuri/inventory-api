<?php
// index.php - entry point for API calls
$request = isset($_GET['request']) ? $_GET['request'] : '';
$requestParts = explode('/', trim($request, '/'));

if (empty($requestParts[0])) {
    header("HTTP/1.0 404 Not Found");
    exit;
}

$resource = array_shift($requestParts);
$file = __DIR__ . "/{$resource}.php";

if (!file_exists($file)) {
    header("HTTP/1.0 404 Not Found");
    exit;
}

// Pass any remaining URL segments (for example, IDs) to the endpoint.
$_GET['params'] = $requestParts;

require_once $file;
?>
