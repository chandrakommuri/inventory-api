<?php
require 'libs/vendor/autoload.php';
require_once 'helpers.php';
require_once 'auth.php';
require_once 'db.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Cell\DataType;


$type = $_GET['type'] ?? 'inward';
$start = $_GET['startDate'] ?? null;
$end = $_GET['endDate'] ?? null;

if (!in_array($type, ['inward', 'outward']) || !$start || !$end) {
    http_response_code(400);
    echo "Invalid parameters.";
    exit;
}

// Set table names based on type
$invoiceTable = $type . '_invoice';
$itemTable = $type . '_invoice_item';
$imeiTable = $type . '_invoice_item_imei';

$inwardSpecificColumns = '';
$outwardSpecificColumns = <<<SQL
    fn_customer_name_by_id(i.customer_id) AS customer_name,
    fn_destination_name_by_id(i.destination_id) AS destination_name,
SQL;
$specificColumns = $type === 'inward' ? $inwardSpecificColumns : $outwardSpecificColumns;

$inwardSpecificColumnNames = [];
$outwardSpecificColumnNames = ['customer_name', 'destination_name'];
$specificColumnNames = $type === 'inward' ? $inwardSpecificColumnNames : $outwardSpecificColumnNames;

$whereDateColumn = $type === 'inward' ? 'i.delivery_date' : 'i.invoice_date';

$query = <<<SQL
    SELECT 
        i.invoice_number,
        i.invoice_date,
        $specificColumns
        p.code AS product_code,
        p.description,
        ii.quantity,
        im.imei
    FROM $invoiceTable i
    JOIN $itemTable ii ON i.id = ii.invoice_id
    JOIN product p ON p.id = ii.product_id
    LEFT JOIN transporter t ON t.id = i.transporter_id
    LEFT JOIN $imeiTable im ON im.{$itemTable}_id = ii.id
    WHERE $whereDateColumn BETWEEN ? AND ?
    AND i.invoice_number not like 'INVENTORY-CORRECTION-%'
    ORDER BY i.id, ii.id, im.id
SQL;

$stmt = $mysqli->prepare($query);
$stmt->bind_param('ss', $start, $end);
$stmt->execute();
$result = $stmt->get_result();

// Group the data
$grouped = [];
while ($row = $result->fetch_assoc()) {
    $invId = $row['invoice_number'];
    $itemId = $row['product_code'];

    if (!isset($grouped[$invId])) {
        $grouped[$invId] = [
            'invoice_number' => $row['invoice_number'],
            'invoice_date' => $row['invoice_date'],
            'items' => []
        ];
        foreach($specificColumnNames as $specificColumnName) {
            $grouped[$invId][$specificColumnName] = $row[$specificColumnName];
        }                
    }

    if (!isset($grouped[$invId]['items'][$itemId])) {
        $grouped[$invId]['items'][$itemId] = [
            'product_code' => $row['product_code'],
            'description' => $row['description'],
            'quantity' => $row['quantity'],
            'imeis' => []
        ];
    }

    if ($row['imei']) {
        $grouped[$invId]['items'][$itemId]['imeis'][] = $row['imei'];
    }
}

// Create spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

$inwardSpecificHeaders = [];
$outwardSpecificHeaders = ['CUSTOMER NAME', 'DESTINATION'];
$specificHeaders = $type === 'inward' ? $inwardSpecificHeaders : $outwardSpecificHeaders;

// Header
$headers = array_merge(['S.NO', 'INVOICE NO', 'INVOICE DATE'], $specificHeaders, ['PRODUCT CODE', 'PRODUCT DESCRIPTION', 'QUANTITY']);

$nonImeiHeaderCount = count($headers);
$productCodeIndex = $nonImeiHeaderCount - 3;
$productCodeColumn = chr(ord('A') + $productCodeIndex);

// IMEIs starting from column
$imeiIndex = $nonImeiHeaderCount;            

$imeiCols = 10; // 10 IMEIs per column
$maxIMEICols = 1000; // up to IMEI NO 10 (can be adjusted)

for ($i = 1; $i <= $maxIMEICols; $i++) {
    $headers[] = "IMEI NO $i";
}
$sheet->fromArray($headers, null, 'A1');

$rowIndex = 2;
$itemSerial = 1;

foreach ($grouped as $invoice) {
    foreach ($invoice['items'] as $item) {
        $imeis = $item['imeis'];
        $imeiChunks = array_chunk($imeis, 10); // each column = 10 IMEIs
        $rowsNeeded = max(count($imeiChunks[0] ?? []), 1);

        for ($r = 0; $r < $rowsNeeded; $r++) {
            $row = [];

            if ($r === 0) {
                $row[] = $itemSerial++;
                $row[] = $invoice['invoice_number'];
                $row[] = $invoice['invoice_date'];
                foreach($specificColumnNames as $specificColumnName) {
                    $row[] = $invoice[$specificColumnName];
                } 
                $row[] = $item['product_code'];
                $row[] = $item['description'];
                $row[] = $item['quantity'];
            } else {
                $row = array_fill(0, $nonImeiHeaderCount, '');
            }

            // IMEIs by column
            for ($i = 0; $i < $maxIMEICols; $i++) {
                $chunk = $imeiChunks[$i] ?? [];
                $row[] = $chunk[$r] ?? '';
            }

            $sheet->fromArray($row, null, "A{$rowIndex}");
            $sheet->setCellValueExplicit("{$productCodeColumn}{$rowIndex}", (string)$row[$productCodeIndex], DataType::TYPE_STRING);

            $imeiColumn = chr(ord('A') + $imeiIndex);
            for ($i = $imeiIndex; $i < count($row); $i++) {
                if (!empty($row[$i])) {
                    $sheet->setCellValueExplicit("{$imeiColumn}{$rowIndex}", (string)$row[$i], DataType::TYPE_STRING);
                }
                $imeiColumn++;
            }
            $rowIndex++;
        }
    }
}
foreach (range('A', 'Z') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}


// Output
$filename = $type . "_" . "invoice_report_" . date('Y-m-d') . ".xlsx";
header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
header("Content-Disposition: attachment;filename=\"$filename\"");
header("Cache-Control: max-age=0");

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
