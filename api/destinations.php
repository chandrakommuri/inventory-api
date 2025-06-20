<?php
require_once 'helpers.php';
require_once 'auth.php';
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];
$params = $_GET['params'] ?? [];

switch ($method) {
    case 'GET':
        if (isset($params[0]) && is_numeric($params[0])) {
            $id = intval($params[0]);
            $stmt = $mysqli->prepare("SELECT * FROM destination WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $row['id'] = intVal($row['id']);
                sendResponse($row);
            } else {
                sendNotFound("Destination not found");
            }
        } else {
            $result = $mysqli->query("SELECT * FROM destination");
            $destinations = [];
            while ($row = $result->fetch_assoc()) {
                $row['id'] = intVal($row['id']);
                $destinations[] = $row;
            }
            sendResponse($destinations);
        }
        break;

    case 'POST':
        $data = getJsonInput();
        if (!isset($data['name'])) {
            sendResponse(['error' => 'Missing destination name'], 400);
        }
        $stmt = $mysqli->prepare("INSERT INTO destination (name) VALUES (?)");
        $stmt->bind_param("s", $data['name']);
        if ($stmt->execute()) {
            sendResponse(['message' => 'Destination created', 'id' => $mysqli->insert_id], 201);
        } else {
            sendResponse(['error' => 'Insert failed'], 500);
        }
        break;

    case 'PUT':
        if (!isset($params[0]) || !is_numeric($params[0])) {
            sendResponse(['error' => 'Destination ID required'], 400);
        }
        $id = intval($params[0]);

        $check = $mysqli->prepare("SELECT id FROM destination WHERE id = ?");
        $check->bind_param("i", $id);
        $check->execute();
        $result = $check->get_result();
        if ($result->num_rows === 0) {
            sendNotFound("Destination not found");
        }

        $data = getJsonInput();
        if (!isset($data['name'])) {
            sendResponse(['error' => 'Missing destination name'], 400);
        }
        $stmt = $mysqli->prepare("UPDATE destination SET name = ? WHERE id = ?");
        $stmt->bind_param("si", $data['name'], $id);
        if ($stmt->execute()) {
            sendResponse(['message' => 'Destination updated']);
        } else {
            sendResponse(['error' => 'Update failed'], 500);
        }
        break;

    case 'DELETE':
        if (!isset($params[0]) || !is_numeric($params[0])) {
            sendResponse(['error' => 'Destination ID required'], 400);
        }
        $id = intval($params[0]);

        $check = $mysqli->prepare("SELECT id FROM destination WHERE id = ?");
        $check->bind_param("i", $id);
        $check->execute();
        $result = $check->get_result();
        if ($result->num_rows === 0) {
            sendNotFound("Destination not found");
        }
        
        $stmt = $mysqli->prepare("DELETE FROM destination WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            sendResponse(['message' => 'Destination deleted']);
        } else {
            sendResponse(['error' => 'Deletion failed'], 500);
        }
        break;

    default:
        sendResponse(['error' => 'Method not allowed'], 405);
        break;
}
?>
