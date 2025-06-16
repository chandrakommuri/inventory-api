<?php
require_once 'helpers.php';
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];
$params = $_GET['params'] ?? [];

switch ($method) {
    case 'GET':
        if (isset($params[0]) && is_numeric($params[0])) {
            $id = intval($params[0]);
            $stmt = $mysqli->prepare("SELECT * FROM customer WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $row['id'] = intVal($row['id']);
                sendResponse($row);
            } else {
                sendNotFound("Customer not found");
            }
        } else {
            $result = $mysqli->query("SELECT * FROM customer");
            $customers = [];
            while ($row = $result->fetch_assoc()) {
                $row['id'] = intVal($row['id']);
                $customers[] = $row;
            }
            sendResponse($customers);
        }
        break;

    case 'POST':
        $data = getJsonInput();
        if (!isset($data['name'])) {
            sendResponse(['error' => 'Missing customer name'], 400);
        }
        $stmt = $mysqli->prepare("INSERT INTO customer (name) VALUES (?)");
        $stmt->bind_param("s", $data['name']);
        if ($stmt->execute()) {
            sendResponse(['message' => 'Customer created', 'id' => $mysqli->insert_id], 201);
        } else {
            sendResponse(['error' => 'Insert failed'], 500);
        }
        break;

    case 'PUT':
        if (!isset($params[0]) || !is_numeric($params[0])) {
            sendResponse(['error' => 'Customer ID required'], 400);
        }
        $id = intval($params[0]);

        $check = $mysqli->prepare("SELECT id FROM customer WHERE id = ?");
        $check->bind_param("i", $id);
        $check->execute();
        $result = $check->get_result();
        if ($result->num_rows === 0) {
            sendNotFound("Customer not found");
        }

        $data = getJsonInput();
        if (!isset($data['name'])) {
            sendResponse(['error' => 'Missing customer name'], 400);
        }
        $stmt = $mysqli->prepare("UPDATE customer SET name = ? WHERE id = ?");
        $stmt->bind_param("si", $data['name'], $id);
        if ($stmt->execute()) {
            sendResponse(['message' => 'Customer updated']);
        } else {
            sendResponse(['error' => 'Update failed'], 500);
        }
        break;

    case 'DELETE':
        if (!isset($params[0]) || !is_numeric($params[0])) {
            sendResponse(['error' => 'Customer ID required'], 400);
        }
        $id = intval($params[0]);

        $check = $mysqli->prepare("SELECT id FROM customer WHERE id = ?");
        $check->bind_param("i", $id);
        $check->execute();
        $result = $check->get_result();
        if ($result->num_rows === 0) {
            sendNotFound("Customer not found");
        }

        $stmt = $mysqli->prepare("DELETE FROM customer WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            sendResponse(['message' => 'Customer deleted']);
        } else {
            sendResponse(['error' => 'Deletion failed'], 500);
        }
        break;

    default:
        sendResponse(['error' => 'Method not allowed'], 405);
        break;
}
?>
