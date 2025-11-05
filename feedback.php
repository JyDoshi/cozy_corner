<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

require 'config.php';

$action = $_GET['action'] ?? 'list';
$data = json_decode(file_get_contents('php://input'), true);

if ($action == 'list') {
    $res = $conn->query("SELECT * FROM feedback ORDER BY timestamp DESC");
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    echo json_encode($rows); 
    exit;
}

if ($action == 'add') {
    $user_id = $data['user_id'] ?? 0;
    $message = $data['message'] ?? '';
    $message = $conn->real_escape_string($message);

    if ($message != '') {
        $stmt = $conn->prepare("INSERT INTO feedback (user_id, message) VALUES (?, ?)");
        $stmt->bind_param('is', $user_id, $message);
        $success = $stmt->execute();
        echo json_encode(['success' => $success, 'id' => $stmt->insert_id]); 
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'Message is empty']); 
    exit;
}

if ($action == "delete") {
    $id = $_GET['id'] ?? '';
    if (!$id) {
        echo json_encode(["success" => false, "message" => "Missing id"]);
        exit;
    }
    $sql = "DELETE FROM feedback WHERE id = '$id'";
    if ($conn->query($sql) === TRUE) {
        echo json_encode(["success" => true]);
    } else {
        echo json_encode(["success" => false, "message" => $conn->error]);
    }
}
?>
