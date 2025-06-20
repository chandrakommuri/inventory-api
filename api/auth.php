<?php
require_once 'helpers.php';

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    sendResponse(['error' => 'Authorization details not found'], 401);
}
$token = $matches[1];
$userData = validateToken($token);
?>
