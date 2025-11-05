<?php
// config.php - update DB credentials if needed
$DB_HOST = '127.0.0.1';
// $DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'cozy_corner';

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($conn->connect_error) {
    die(json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]));
}
$conn->set_charset('utf8mb4');
?>