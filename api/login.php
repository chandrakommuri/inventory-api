<?php
require_once 'helpers.php';

$data = getJsonInput();
$token = $data['token'] ?? '';
$userData = validateToken($token);
sendResponse(['message' => 'Authenticated', 'userData' => $userData], 200);
?>