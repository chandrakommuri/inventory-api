<?php
require_once 'helpers.php';
require_once 'auth.php';
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];
$params = isset($_GET['params']) ? $_GET['params'] : [];

switch($method) {
    case 'GET':
        if (isset($params[0]) && is_numeric($params[0])) {
            // Get a single product by id
            $id = intval($params[0]);
            // Get all products
            $query = <<<'SQL'
                SELECT 
                    p.*,
                    IF(COUNT(pi.imei), 
                        JSON_ARRAYAGG(pi.imei), 
                        JSON_ARRAY()
                    ) AS imeis
                FROM v_products p
                LEFT JOIN v_available_product_imeis pi ON p.id = pi.product_id
                WHERE p.id = ?
                GROUP BY p.id
            SQL;
            $stmt = $mysqli->prepare($query);
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            if($row = $result->fetch_assoc()){
                $row['id'] = intVal($row['id']);
                $row['inward_quantity'] = intVal($row['inward_quantity']);
                $row['outward_quantity'] = intVal($row['outward_quantity']);
                $row['quantity'] = intVal($row['quantity']);
                sendResponse($row);
            } else {
                sendNotFound("Product not found");
            }
        } else {
            // Get all products
            $query = <<<'SQL'
                SELECT 
                    p.*,
                    IF(COUNT(pi.imei), 
                        JSON_ARRAYAGG(pi.imei), 
                        JSON_ARRAY()
                    ) AS imeis
                FROM v_products p
                LEFT JOIN v_available_product_imeis pi ON p.id = pi.product_id
                GROUP BY p.id
            SQL;
            $result = $mysqli->query($query);
            $products = [];
            while ($row = $result->fetch_assoc()) {
                $row['id'] = intVal($row['id']);
                $row['inward_quantity'] = intVal($row['inward_quantity']);
                $row['outward_quantity'] = intVal($row['outward_quantity']);
                $row['quantity'] = intVal($row['quantity']);
                $row['imeis'] = json_decode($row['imeis'], true);
                $products[] = $row;
            }
            sendResponse($products);
        }
        break;
        
    case 'POST':
        $data = getJsonInput();
        if (!isset($data['code']) || !isset($data['description'])) {
            sendResponse(['error' => 'Invalid input'], 400);
        }
        $stmt = $mysqli->prepare("INSERT INTO product (code, description) VALUES (?, ?)");
        $stmt->bind_param("ss", $data['code'], $data['description']);
        if ($stmt->execute()) {
            $id = $mysqli->insert_id;
            sendResponse(['message' => 'Product created', 'id' => $id], 201);
        } else {
            sendResponse(['error' => 'Insert failed'], 500);
        }
        break;
        
    case 'PUT':
        if (!isset($params[0]) || !is_numeric($params[0])) {
            sendResponse(['error' => 'ID is required'], 400);
        }
        $id = intval($params[0]);

        $check = $mysqli->prepare("SELECT id FROM product WHERE id = ?");
        $check->bind_param("i", $id);
        $check->execute();
        $result = $check->get_result();
        if ($result->num_rows === 0) {
            sendNotFound("Product not found");
        }
        
        $data = getJsonInput();
        if (!isset($data['code']) || !isset($data['description'])) {
            sendResponse(['error' => 'Invalid input'], 400);
        }
        $stmt = $mysqli->prepare("UPDATE product SET code=?, description = ? WHERE id = ?");
        $stmt->bind_param("ssi", $data['code'], $data['description'], $id);
        if ($stmt->execute()) {
            sendResponse(['message' => 'Product updated']);
        } else {
            sendResponse(['error' => 'Update failed'], 500);
        }
        break;
        
    case 'DELETE':
        if (!isset($params[0]) || !is_numeric($params[0])) {
            sendResponse(['error' => 'ID is required'], 400);
        }
        $id = intval($params[0]);

        $check = $mysqli->prepare("SELECT quantity FROM v_products WHERE id = ?");
        $check->bind_param("i", $id);
        $check->execute();
        $result = $check->get_result();
        if($row = $result->fetch_assoc()) {
            if($row['quantity'] > 0) {
                sendResponse("Product cannot be deleted when physical quantity exists!", 500);
            }
        } else {
            sendNotFound("Product not found");
        }
        
        $stmt = $mysqli->prepare("UPDATE product SET deleted=1 WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            sendResponse(['message' => 'Product deleted']);
        } else {
            sendResponse(['error' => 'Deletion failed'], 500);
        }
        break;
        
    default:
        sendResponse(['error' => 'Method not allowed'], 405);
        break;
}
?>
