<?php
require_once 'helpers.php';
require 'db.php';

try {
    $summary = [];

    // 1. Total Physical Quantity
    $result = $mysqli->query("SELECT SUM(quantity) FROM v_products");
    $summary['totalPhysicalQuantity'] = (int) $result->fetch_row()[0];

    // 2. Total Inward Quantity
    $result = $mysqli->query("SELECT SUM(quantity) FROM inward_invoice_item");
    $summary['totalInwardQuantity'] = (int) $result->fetch_row()[0];

    // 3. Total Outward Quantity
    $result = $mysqli->query("SELECT SUM(quantity) FROM outward_invoice_item");
    $summary['totalOutwardQuantity'] = (int) $result->fetch_row()[0];

    // 4. Total Damaged Products
    $result = $mysqli->query("SELECT COUNT(*) FROM inward_invoice_item_imei WHERE damaged = 1");
    $summary['totalDamagedQuantity'] = (int) $result->fetch_row()[0];

    // 5. Total Inward Invoices
    $result = $mysqli->query("SELECT COUNT(*) FROM inward_invoice");
    $summary['totalInwardInvoices'] = (int) $result->fetch_row()[0];

    // 6. Total Outward Invoices
    $result = $mysqli->query("SELECT COUNT(*) FROM outward_invoice");
    $summary['totalOutwardInvoices'] = (int) $result->fetch_row()[0];

    // 7. Total Products
    $result = $mysqli->query("SELECT COUNT(*) FROM product where deleted <> 1");
    $summary['totalProducts'] = (int) $result->fetch_row()[0];

    // 8. Total Customers
    $result = $mysqli->query("SELECT COUNT(*) FROM customer");
    $summary['totalCustomers'] = (int) $result->fetch_row()[0];

    // 9. Total Destinations
    $result = $mysqli->query("SELECT COUNT(*) FROM destination");
    $summary['totalDestinations'] = (int) $result->fetch_row()[0];

    sendResponse($summary);
} catch (Exception $e) {
    sendResponse(['message' => 'Error: ' . $e->getMessage()]);
}
