<?php
require_once 'helpers.php';
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

switch($method) {
    case 'GET':
            if (!isset($_GET['startDate']) || !isset($_GET['endDate'])) {
                sendResponse(['error' => 'Missing startDate or endDate'], 400);
            }

            $startDate = $_GET['startDate'];
            $endDate = $_GET['endDate'];
            $dateRegex = '/^\d{4}-\d{2}-\d{2}$/';

            if (!preg_match($dateRegex, $startDate) || !preg_match($dateRegex, $endDate)) {
                sendResponse(['error' => 'Invalid date format. Use YYYY-MM-DD.'], 400);
            }

            $query = <<<SQL
            WITH RECURSIVE date_range AS (
                SELECT ? AS date
                UNION ALL
                SELECT DATE_ADD(date, INTERVAL 1 DAY)
                FROM date_range
                WHERE date < ?
            ),
            product_dates AS (
                SELECT p.id AS product_id, p.code, p.description, d.date
                FROM product p
                CROSS JOIN date_range d
            ),
            received AS (
                SELECT ii.delivery_date AS date, iii.product_id, SUM(iii.quantity) AS qty_received
                FROM inward_invoice ii
                JOIN inward_invoice_item iii ON ii.id = iii.invoice_id
                WHERE ii.delivery_date BETWEEN ? AND ?
                GROUP BY iii.product_id, ii.delivery_date
            ),
            sent AS (
                SELECT oi.invoice_date AS date, oii.product_id, SUM(oii.quantity) AS qty_sent
                FROM outward_invoice oi
                JOIN outward_invoice_item oii ON oi.id = oii.invoice_id
                WHERE oi.invoice_date BETWEEN ? AND ?
                GROUP BY oii.product_id, oi.invoice_date
            )
            SELECT
                pd.code AS product_code,
                pd.description AS product_description,
                pd.date,
                COALESCE(r.qty_received, 0) AS qty_received,
                COALESCE(s.qty_sent, 0) AS qty_sent
            FROM product_dates pd
            LEFT JOIN received r ON r.product_id = pd.product_id AND r.date = pd.date
            LEFT JOIN sent s ON s.product_id = pd.product_id AND s.date = pd.date
            ORDER BY pd.code, pd.date;
            SQL;

            $stmt = $mysqli->prepare($query);
            if (!$stmt) {
                sendResponse(['error' => 'Prepare failed: ' . $mysqli->error], 500);
            }

            $stmt->bind_param("ssssss", $startDate, $endDate, $startDate, $endDate, $startDate, $endDate);

            if (!$stmt->execute()) {
                sendResponse(['error' => 'Execute failed: ' . $stmt->error], 500);
            }

            $result = $stmt->get_result();
            $grouped = [];

            while ($row = $result->fetch_assoc()) {
                $code = $row['product_code'];
                $date = $row['date'];

                if (!isset($grouped[$code])) {
                    $grouped[$code] = [
                        'productCode' => $code,
                        'productDescription' => $row['product_description'],
                    ];
                }

                $grouped[$code]["{$date}_IN"] = (int) $row['qty_received'];
                $grouped[$code]["{$date}_OUT"] = (int) $row['qty_sent'];
            }

            sendResponse(array_values($grouped), 200, false);
        break;        
    default:
        sendResponse(['error' => 'Method not allowed'], 405);
        break;
}
?>
