<?php
session_start();
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

require 'config.php';

$user_id = $_GET['id'] ?? 0;

if(!$user_id){
    echo json_encode(['success' => false, 'message' => 'Missing user ID']);
    exit;
}

// ✅ Fetch user details + optional room details
$sql = "SELECT 
            u.id, 
            u.name, 
            u.email, 
            u.contact, 
            u.role,
            r.room_id,
            r.capacity, 
            r.floor
        FROM users u
        LEFT JOIN room_members rm ON u.id = rm.user_id
        LEFT JOIN rooms r ON rm.room_id = r.room_id
        WHERE u.id = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();

if($row = $res->fetch_assoc()){
    $user = [
        "id" => $row["id"],
        "name" => $row["name"],
        "email" => $row["email"],
        "contact" => $row["contact"],
        "role" => $row["role"],
        "room" => $row["room_id"] ? [
            "id" => $row["room_id"],
            "name" => $row["room_name"],
            "capacity" => $row["capacity"],
            "floor" => $row["floor"]
        ] : null
    ];

    echo json_encode(['success' => true, 'user' => $user]);
} else {
    echo json_encode(['success' => false, 'message' => 'User not found']);
}
?>
