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
$whereDateColumn = $type === 'inward' ? 'i.delivery_date' : 'i.invoice_date';

$query = <<<SQL
    SELECT 
        i.invoice_number,
        i.invoice_date,
        p.code as product_code,
        p.description,
        ii.quantity,
        im.imei
    FROM $invoiceTable i
    JOIN $itemTable ii ON i.id = ii.invoice_id
    JOIN product p ON p.id = ii.product_id
    LEFT JOIN transporter t ON t.id = i.transporter_id
    LEFT JOIN $imeiTable im ON im.{$itemTable}_id = ii.id
    WHERE $whereDateColumn BETWEEN ? AND ?
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

// Header
$headers = ['S.NO', 'INVOICE NO', 'INVOICE DATE', 'PRODUCT CODE', 'PRODUCT DESCRIPTION', 'QUANTITY'];
$imeiCols = 10; // 10 IMEIs per column
$maxIMEICols = 10; // up to IMEI NO 10 (can be adjusted)

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
                $row[] = $item['product_code'];
                $row[] = $item['description'];
                $row[] = $item['quantity'];
            } else {
                $row = array_fill(0, 6, '');
            }

            // IMEIs by column
            for ($i = 0; $i < $maxIMEICols; $i++) {
                $chunk = $imeiChunks[$i] ?? [];
                $row[] = $chunk[$r] ?? '';
            }

            $sheet->fromArray($row, null, "A{$rowIndex}");
            // Product Code in column D
            $sheet->setCellValueExplicit("D{$rowIndex}", (string)$row[3], DataType::TYPE_STRING);

            // IMEIs starting from column G (index 6 as $row[6] onward)
            $col = 'G';
            for ($i = 6; $i < count($row); $i++) {
                if (!empty($row[$i])) {
                    $sheet->setCellValueExplicit("{$col}{$rowIndex}", (string)$row[$i], DataType::TYPE_STRING);
                }
                $col++;
            }
            $rowIndex++;
        }
    }
}
// Format Product Code and IMEI columns as text to avoid scientific notation
$sheet->getStyle('D2:D1000')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT); // Product Code
$sheet->getStyle('G2:AZ1000')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT); // IMEIs (G onwards)
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
