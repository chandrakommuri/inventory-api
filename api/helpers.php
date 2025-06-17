<?php

// Allow CORS for development
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");

// Short-circuit OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); // No Content
    exit;
}

/**
 * Sends a JSON response with the given status code,
 * converting all array keys to camelCase.
 */
function sendResponse($data, $status_code = 200, $convert=true) {
    http_response_code($status_code);
    header("Content-Type: application/json");
    if($convert) {
        $data = convertKeysToCamelCase($data);
    }
    echo json_encode($data);
    exit;
}

/**
 * Sends a 404 response.
 */
function sendNotFound($message = "Resource not found") {
    sendResponse(['error' => $message], 404);
}

/**
 * Recursively convert all array keys to camelCase.
 */
function convertKeysToCamelCase($data) {
    if (is_array($data)) {
        $newData = [];
        foreach ($data as $key => $value) {
            $newKey = is_string($key) ? toCamelCase($key) : $key;
            $newData[$newKey] = convertKeysToCamelCase($value);
        }
        return $newData;
    }
    return $data;
}

/**
 * Converts snake_case to camelCase (e.g., invoice_number → invoiceNumber)
 */
function toCamelCase($string) {
    return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $string))));
}

/**
 * Reads and decodes JSON input from the request, and converts all keys to snake_case.
 */
function getJsonInput() {
    $json = json_decode(file_get_contents('php://input'), true);
    return convertKeysToSnakeCase($json);
}

/**
 * Recursively convert all array keys to snake_case.
 */
function convertKeysToSnakeCase($data) {
    if (is_array($data)) {
        $newData = [];
        foreach ($data as $key => $value) {
            $newKey = is_string($key) ? toSnakeCase($key) : $key;
            $newData[$newKey] = convertKeysToSnakeCase($value);
        }
        return $newData;
    }
    return $data;
}

/**
 * Converts camelCase or PascalCase to snake_case.
 */
function toSnakeCase($string) {
    return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $string));
}
?>
