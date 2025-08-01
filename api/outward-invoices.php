<?php
require_once 'helpers.php';
require_once 'auth.php';
require_once 'db.php';

/**
 * Retrieves an outward invoice with its items.
 */
function getOutInvoiceWithItems($mysqli, $invoice_id) {
    // Fetch invoice
    $stmt = $mysqli->prepare("SELECT i.*, t.name as transporter, c.name as customer, d.name as destination FROM outward_invoice i, transporter t, customer c, destination d WHERE i.transporter_id = t.id and i.customer_id = c.id and i.destination_id = d.id and i.id = ?");
    $stmt->bind_param("i", $invoice_id);
    $stmt->execute();
    $invoice = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$invoice) {
        return null;
    }
    // Fetch items
    $stmtItems = $mysqli->prepare("SELECT ii.*, p.code, p.description FROM outward_invoice_item ii, product p WHERE ii.product_id = p.id and ii.invoice_id = ?");
    $stmtItems->bind_param("i", $invoice_id);
    $stmtItems->execute();
    $resultItems = $stmtItems->get_result();
    $stmtItems->close();

    $items = [];
    while($row = $resultItems->fetch_assoc()){
        $imeis = [];

        // Fetch imeis for each item
        $stmtImeis = $mysqli->prepare("SELECT * FROM outward_invoice_item_imei WHERE outward_invoice_item_id = ?");
        $stmtImeis->bind_param("i", $row["id"]);
        $stmtImeis->execute();
        $imeisResult = $stmtImeis->get_result();

        while ($imeiRow = $imeisResult->fetch_assoc()) {
            $imeis[] = $imeiRow['imei'];
        }

        $stmtImeis->close();

        $row['imeis'] = $imeis;
        $items[] = $row;
    }

    $invoice['items'] = $items;
    return $invoice;
}

$method = $_SERVER['REQUEST_METHOD'];
$params = isset($_GET['params']) ? $_GET['params'] : [];

switch($method) {
    case 'GET':
        if(isset($params[0]) && is_numeric($params[0])){
            $id = intval($params[0]);
            $invoice = getOutInvoiceWithItems($mysqli, $id);
            if ($invoice) {
                sendResponse($invoice);
            } else {
                sendNotFound("Invoice not found");
            }
        } else {
            // List all invoices along with their items.
            $result = $mysqli->query("SELECT * FROM outward_invoice");
            $invoices = [];
            while($row = $result->fetch_assoc()) {
                $invoices[] = getOutInvoiceWithItems($mysqli, $row['id']);
            }
            sendResponse($invoices);
        }
        break;
        
    case 'POST':
        $data = getJsonInput();
        if (!isset($data['invoice_number'], $data['invoice_date'], $data['customer_id'], $data['destination_id'], $data['transporter_id'], $data['docket_number'], $data['items'])
            || !is_array($data['items'])) {
            sendResponse(['error' => 'Invalid input'], 400);
        }

        $mysqli->begin_transaction();

        try {
            // Insert invoice
            $stmt = $mysqli->prepare("INSERT INTO outward_invoice (invoice_number, invoice_date, customer_id, destination_id, transporter_id, docket_number) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssiiis", $data['invoice_number'], $data['invoice_date'], $data['customer_id'], $data['destination_id'], $data['transporter_id'], $data['docket_number']);
            if(!$stmt->execute()) {
                throw new Exception("Invoice insert failed");
            }
            $invoice_id = $mysqli->insert_id;
            $stmt->close();

            // Prepare insert for items and imeis
            $stmtItem = $mysqli->prepare("INSERT INTO outward_invoice_item (invoice_id, product_id, quantity) VALUES (?, ?, ?)");
            $stmtImei = $mysqli->prepare("INSERT INTO outward_invoice_item_imei (outward_invoice_item_id, imei) VALUES (?, ?)");

            foreach($data['items'] as $item) {
                if (!isset($item['product_id'], $item['quantity'], $item['imeis']) || !is_array($item['imeis']) || count($item['imeis']) === 0) {
                    throw new Exception("Invalid item or IMEIs data");
                }

                $stmtItem->bind_param("iii", $invoice_id, $item['product_id'], $item['quantity']);
                if (!$stmtItem->execute()) {
                    throw new Exception("Item insert failed");
                }

                $invoice_item_id = $mysqli->insert_id;

                foreach ($item['imeis'] as $imei) {
                    if (!is_string($imei) || trim($imei) === "") {
                        throw new Exception("Invalid IMEI value");
                    }

                    $stmtImei->bind_param("is", $invoice_item_id, $imei);
                    if (!$stmtImei->execute()) {
                        throw new Exception("IMEI insert failed");
                    }
                }
            }

            // Clean up
            $stmtItem->close();
            $stmtImei->close();

            $mysqli->commit();
            sendResponse(['message' => 'Outward invoice with items created', 'invoice_id' => $invoice_id], 201);

        } catch(Exception $e) {
            $mysqli->rollback();
            sendResponse(['error' => $e->getMessage()], 500);
        }
        break;
        
    case 'PUT':
        if(!isset($params[0]) || !is_numeric($params[0])){
            sendResponse(['error' => 'Invoice ID required'], 400);
        }
        $invoice_id = intval($params[0]);

        $check = $mysqli->prepare("SELECT id FROM outward_invoice WHERE id = ?");
        $check->bind_param("i", $invoice_id);
        $check->execute();
        $result = $check->get_result();
        if ($result->num_rows === 0) {
            sendNotFound("Invoice not found");
        }

        $data = getJsonInput();
        $mysqli->begin_transaction();
        try {
            if(!isset($data['invoice_number'], $data['invoice_date'], $data['customer_id'], $data['destination_id'], $data['transporter_id'], $data['docket_number'])) {
                throw new Exception("Invalid invoice data");
            }
            $stmt = $mysqli->prepare("UPDATE outward_invoice SET invoice_number = ?, invoice_date = ?, customer_id = ?, destination_id = ?, transporter_id = ?, docket_number = ? WHERE id = ?");
            $stmt->bind_param("ssiiisi", $data['invoice_number'], $data['invoice_date'], $data['customer_id'], $data['destination_id'], $data['transporter_id'], $data['docket_number'], $invoice_id);
            if(!$stmt->execute()){
                throw new Exception("Invoice update failed");
            }
            // Update items: for simplicity, remove old items and re-insert if provided.
            if(isset($data['items']) && is_array($data['items'])){
                $mysqli->query("DELETE FROM outward_invoice_item_imei WHERE outward_invoice_item_id IN (SELECT id FROM outward_invoice_item WHERE invoice_id = $invoice_id)");
                $mysqli->query("DELETE FROM outward_invoice_item WHERE invoice_id = $invoice_id");

                $stmtItem = $mysqli->prepare("INSERT INTO outward_invoice_item (invoice_id, product_id, quantity) VALUES (?, ?, ?)");
                foreach($data['items'] as $item){
                    if(!isset($item['product_id'], $item['quantity'], $item['imeis'])){
                        throw new Exception("Invalid item data");
                    }
                    $stmtItem->bind_param("iii", $invoice_id, $item['product_id'], $item['quantity']);
                    if(!$stmtItem->execute()){
                        throw new Exception("Item insert failed");
                    }
                    $invoice_item_id = $mysqli->insert_id;
                    $stmtImei = $mysqli->prepare("INSERT INTO outward_invoice_item_imei (outward_invoice_item_id, imei) VALUES (?, ?)");
                    foreach ($item['imeis'] as $imei) {
                        $stmtImei->bind_param("is", $invoice_item_id, $imei);
                        if (!$stmtImei->execute()){
                            throw new Exception("Item insert failed");
                        }
                    }
                }
            }
            $mysqli->commit();
            sendResponse(['message' => 'Outward invoice updated']);
        } catch(Exception $e) {
            $mysqli->rollback();
            sendResponse(['error' => $e->getMessage()], 500);
        }
        break;
        
    case 'DELETE':
        if(!isset($params[0]) || !is_numeric($params[0])){
            sendResponse(['error' => 'Invoice ID required'], 400);
        }
        $invoice_id = intval($params[0]);

        $check = $mysqli->prepare("SELECT id FROM outward_invoice WHERE id = ?");
        $check->bind_param("i", $invoice_id);
        $check->execute();
        $result = $check->get_result();
        if ($result->num_rows === 0) {
            sendNotFound("Invoice $invoice_id not found");
        }

        $mysqli->begin_transaction();
        try {
            // Delete invoice item imeis
            $stmtImeis = $mysqli->prepare("DELETE FROM outward_invoice_item_imei WHERE outward_invoice_item_id IN (SELECT id FROM outward_invoice_item WHERE invoice_id = ?)");
            $stmtImeis->bind_param("i", $invoice_id);
            $stmtImeis->execute();
            // Delete invoice items
            $stmtItems = $mysqli->prepare("DELETE FROM outward_invoice_item WHERE invoice_id = ?");
            $stmtItems->bind_param("i", $invoice_id);
            $stmtItems->execute();
            // Delete the invoice
            $stmt = $mysqli->prepare("DELETE FROM outward_invoice WHERE id = ?");
            $stmt->bind_param("i", $invoice_id);
            if(!$stmt->execute()){
                throw new Exception("Invoice deletion failed");
            }
            $mysqli->commit();
            sendResponse(['message' => 'Outward invoice and its items deleted']);
        } catch(Exception $e) {
            $mysqli->rollback();
            sendResponse(['error' => $e->getMessage()], 500);
        }
        break;
        
    default:
        sendResponse(['error' => 'Method not allowed'], 405);
        break;
}
?>
