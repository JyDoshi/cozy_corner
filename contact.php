<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json");

require 'config.php'; // Include DB connection

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        "success" => false,
        "message" => "Invalid request method"
    ]);
    exit;
}

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

$name = isset($data['user_name']) ? trim($data['user_name']) : '';
$email = isset($data['email']) ? trim($data['email']) : '';
$message = isset($data['message']) ? trim($data['message']) : '';

if (empty($name) || empty($email) || empty($message)) {
    echo json_encode([
        "success" => false,
        "message" => "All fields are required"
    ]);
    exit;
}

try {
    $stmt = $conn->prepare("INSERT INTO contacts (user_name, email, message) VALUES (?, ?, ?)");

    if (!$stmt) {
        echo json_encode([
            "success" => false,
            "message" => "Prepare failed",
            "error" => $conn->error
        ]);
        exit;
    }

    $stmt->bind_param("sss", $name, $email, $message);

    if ($stmt->execute()) {
        echo json_encode([
            "success" => true,
            "message" => "Submitted successfully"
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "message" => "Execute failed",
            "error" => $stmt->error
        ]);
    }

    $stmt->close();
    $conn->close();

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Exception occurred",
        "error" => $e->getMessage(),
        "trace" => $e->getTraceAsString()
    ]);
}
?>

