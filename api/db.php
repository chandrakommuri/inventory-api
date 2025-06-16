<?php
// Database connection
function getDBConnection() {
    $host = 'localhost';
    $user = 'sri4wayexpress_admin';
    $password = '9866299856@sri4way';
    $dbname = 'sri4wayexpress_inventory';

    $mysqli = new mysqli($host, $user, $password, $dbname);
    if ($mysqli->connect_error) {
        die(json_encode(['error' => 'Database connection failed: ' . $mysqli->connect_error]));
    }
    $mysqli->set_charset("utf8");
    return $mysqli;
}

// Create the $mysqli variable globally
$mysqli = getDBConnection();
?>