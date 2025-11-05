<?php
session_start();

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json");

require 'config.php';

$data = json_decode(file_get_contents("php://input"), true);
$action = $data['action'] ?? ($_GET['action'] ?? '');

if ($action === "fetch") {
    $user_id = $data['user_id'] ?? 0; // Get user_id from request
    
    // ✅ Fetch complaints with user info - filter by user_id if provided
    if (!empty($user_id)) {
        // User panel - fetch only user's complaints
        $sql = "SELECT c.id, c.user_id, c.complaint_text, c.status, c.last_updated,
                       u.name, u.contact, u.email
                FROM complaints c
                LEFT JOIN users u ON c.user_id = u.id
                WHERE c.user_id = ?
                ORDER BY c.last_updated DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        // Admin panel - fetch all complaints
        $sql = "SELECT c.id, c.user_id, c.complaint_text, c.status, c.last_updated,
                       u.name, u.contact, u.email
                FROM complaints c
                LEFT JOIN users u ON c.user_id = u.id
                ORDER BY c.last_updated DESC";
        
        $result = $conn->query($sql);
    }

    $complaints = [];
    while ($row = $result->fetch_assoc()) {
        $complaints[] = $row;
    }

    echo json_encode(["success" => true, "data" => $complaints]);
    exit;
}

if ($action === "add") {
    // ✅ Add new complaint
    $user_id = $data['user_id'] ?? 0;
    $complaint_text = $data['complaint_text'] ?? '';

    if (empty($user_id) || empty($complaint_text)) {
        echo json_encode(["success" => false, "message" => "Missing user_id or complaint text"]);
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO complaints (user_id, complaint_text, status) VALUES (?, ?, 'Pending')");
    $stmt->bind_param("is", $user_id, $complaint_text);

    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "Complaint added successfully"]);
    } else {
        echo json_encode(["success" => false, "message" => "Failed to add complaint"]);
    }
    exit;
}

if ($action === "update") {
    // ✅ Update complaint status
    $id = $data['id'] ?? 0;
    $status = $data['status'] ?? '';

    if (empty($id) || empty($status)) {
        echo json_encode(["success" => false, "message" => "Missing id or status"]);
        exit;
    }

    $stmt = $conn->prepare("UPDATE complaints SET status = ?, last_updated = NOW() WHERE id = ?");
    $stmt->bind_param("si", $status, $id);

    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "Complaint status updated"]);
    } else {
        echo json_encode(["success" => false, "message" => "Failed to update status"]);
    }
    exit;
}

if ($action === "delete") {
    // ✅ Delete complaint
    $id = $data['id'] ?? 0;

    if (empty($id)) {
        echo json_encode(["success" => false, "message" => "Missing complaint id"]);
        exit;
    }

    $stmt = $conn->prepare("DELETE FROM complaints WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "Complaint deleted"]);
    } else {
        echo json_encode(["success" => false, "message" => "Failed to delete complaint"]);
    }
    exit;
}

// Default response
echo json_encode(["success" => false, "message" => "Invalid action"]);
?>