<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json");
require 'config.php';

$input = json_decode(file_get_contents("php://input"), true);
if (!$input) $input = $_POST;

$userId = $input['id'] ?? '';
$oldPassword = $input['old_password'] ?? '';
$newPassword = $input['new_password'] ?? '';

if (empty($userId) || empty($oldPassword) || empty($newPassword)) {
    echo json_encode(["success" => false, "message" => "Missing required fields"]);
    exit;
}

$stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    echo json_encode(["success" => false, "message" => "User not found"]);
    exit;
}

$row = $res->fetch_assoc();
if ($row['password'] !== $oldPassword) {
    echo json_encode(["success" => false, "message" => "Old password is incorrect"]);
    exit;
}

$update1 = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
$update1->bind_param("si", $newPassword, $userId);
$update1->execute();

if ($update1->affected_rows > 0) {
    echo json_encode(["success" => true, "message" => "Password updated successfully"]);
} else {
    echo json_encode(["success" => false, "message" => "No changes made"]);
}
?>
