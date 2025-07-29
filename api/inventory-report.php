<?php
require 'libs/vendor/autoload.php';
require_once 'helpers.php';
require_once 'db.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Create spreadsheet
$spreadsheet = new Spreadsheet();

// === SHEET 1: Inventory ===
$sheet1 = $spreadsheet->getActiveSheet();
$sheet1->setTitle('Inventory');
$sheet1->fromArray(['S.No', 'Product Code', 'Product Description', 'Physical Qty'], null, 'A1');

$query1 = "SELECT id, code, description, quantity FROM v_products";
$result1 = $mysqli->query($query1);
$rowIndex = 2;
$serial = 1;
while ($row = $result1->fetch_assoc()) {
    $sheet1->fromArray([$serial++, $row['code'], $row['description'], $row['quantity']], null, "A$rowIndex");
    $rowIndex++;
}

// === Helper function for chunked IMEI sheets ===
function addImeiSheet($spreadsheet, $sheetTitle, $query, $mysqli) {
    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle($sheetTitle);
    $sheet->fromArray(['S.No', 'Product Code', 'Product Description', 'IMEI'], null, 'A1');

    $stmt = $mysqli->prepare($query);
    $stmt->execute();
    $res = $stmt->get_result();

    $rowIndex = 2;
    $serial = 1;

    while ($row = $res->fetch_assoc()) {
        $imeis = explode(',', $row['imeis']); // assuming comma-separated
        $chunks = array_chunk($imeis, 10);
        foreach ($chunks as $chunk) {
            foreach ($chunk as $i => $imei) {
                if ($i == 0) {
                    $sheet->fromArray([$serial, $row['code'], $row['description'], $imei], null, "A$rowIndex");
                } else {
                    $sheet->setCellValue("D$rowIndex", $imei);
                }
                $rowIndex++;
            }
            $serial++;
        }
    }
}

// === SHEET 2: Inward IMEIs today ===
$inwardQuery = <<<SQL
SELECT p.code, p.description, GROUP_CONCAT(ii.imei) AS imeis
FROM inward_invoice_item_imei ii
INNER JOIN inward_invoice_item i ON i.id = ii.inward_invoice_item_id
INNER JOIN product p ON p.id = i.product_id
INNER JOIN inward_invoice inv ON inv.id = i.invoice_id
WHERE DATE(inv.delivery_date) = CURDATE()
GROUP BY p.code, p.description;
SQL;
addImeiSheet($spreadsheet, 'Inward IMEIs today', $inwardQuery, $mysqli);

// === SHEET 3: Outward IMEIs today ===
$outwardQuery = <<<SQL
SELECT p.code, p.description, GROUP_CONCAT(ii.imei) AS imeis
FROM outward_invoice_item_imei ii
INNER JOIN outward_invoice_item i ON i.id = ii.outward_invoice_item_id
INNER JOIN product p ON p.id = i.product_id
INNER JOIN outward_invoice inv ON inv.id = i.invoice_id
WHERE DATE(inv.invoice_date) = CURDATE()
GROUP BY p.code, p.description;
SQL;
addImeiSheet($spreadsheet, 'Outward IMEIs today', $outwardQuery, $mysqli);

// === SHEET 4: Physical Stock IMEIs ===
$physicalQuery = <<<SQL
SELECT p.code, p.description, GROUP_CONCAT(im.imei) as imeis
FROM product p
LEFT JOIN v_available_product_imeis im on im.product_id = p.id
GROUP BY p.code, p.description;
SQL;
addImeiSheet($spreadsheet, 'Physical Stock IMEIs', $physicalQuery, $mysqli);

// === Save file or stream to browser ===
$filename = 'inventory_' . date('YYYY-MM-DD') . '.xlsx';
header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
header("Content-Disposition: attachment;filename=\"$filename\"");
header("Cache-Control: max-age=0");

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
