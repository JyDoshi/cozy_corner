<?php
session_start();

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json");

require 'config.php';

// Read JSON input
$data = json_decode(file_get_contents("php://input"), true);
$email = $data['email'] ?? '';
$password = $data['password'] ?? '';

if (empty($email) || empty($password)) {
    echo json_encode([
        'success' => false,
        'message' => 'Missing email or password'
    ]);
    exit;
}

// Query user by email
$stmt = $conn->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
$stmt->bind_param('s', $email);
$stmt->execute();
$res = $stmt->get_result();

if ($row = $res->fetch_assoc()) {
    // If not using hashed passwords, do plain compare
    if ($row['password'] === $password) {
        
        // ✅ Store session for any valid role
        $_SESSION['user_id'] = $row['id'];
        $_SESSION['name'] = $row['name'];
        $_SESSION['role'] = $row['role'];
        $_SESSION['email'] = $row['email'];

        // ✅ Step 2: Determine user's room_id
        $roomId = $row['room_id'];

        if (empty($roomId)) {
            // Fallback: check room_members table
            $fallback = $conn->prepare("SELECT room_id FROM room_members WHERE user_id = ? LIMIT 1");
            $fallback->bind_param('i', $row['id']);
            $fallback->execute();
            $fallbackRes = $fallback->get_result();
            if ($fallbackRow = $fallbackRes->fetch_assoc()) {
                $roomId = $fallbackRow['room_id'];
            }
        }

        // ✅ Step 3: Fetch room details if assigned
        $roomData = null;
        if (!empty($roomId)) {
            $roomStmt = $conn->prepare("SELECT room_id, floor, type, rent FROM rooms WHERE room_id = ? LIMIT 1");
            $roomStmt->bind_param('i', $roomId);
            $roomStmt->execute();
            $roomRes = $roomStmt->get_result();
            $roomData = $roomRes->fetch_assoc();
        }

        // ✅ Step 4: Correct types — integers stay as int, text stays as string
        echo json_encode([
            'success' => true,
            'role' => $row['role'] ?? '',
            'status' => $row['status'] ?? '',
            'user' => [
                'id'    => isset($row['id']) ? (int)$row['id'] : 0,
                'name'  => $row['name'] ?? '',
                'email' => $row['email'] ?? '',
                'phone' => $row['contact'] ?? '',
                'room'  => $roomData['room_id'] ?? '',
                'floor' => $roomData['floor'] ?? '',
                'type'  => $roomData['type'] ?? '',
                'rent'  => $roomData['rent'] ?? '',
                'image' => $row['image'] ?? '',
            ]
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid password'
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'User not found'
    ]);
}
?>