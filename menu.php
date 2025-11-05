<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json");
require 'config.php';

$action = $_GET['action'] ?? '';
$data = json_decode(file_get_contents("php://input"), true);

if ($action == "fetch") {
    $result = $conn->query("SELECT * FROM menu ORDER BY date ASC");
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    echo json_encode($rows);

} elseif ($action == "add") {
    $date = trim($data["date"] ?? "");
    $meal_type = trim($data["meal_type"] ?? "");
    $description = trim($data["description"] ?? "");

    if (!empty($date) && !empty($meal_type) && !empty($description)) {
        $stmt = $conn->prepare("INSERT INTO menu (date, meal_type, description) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $date, $meal_type, $description);
        $success = $stmt->execute();
        echo json_encode(["success" => $success]);
    } else {
        echo json_encode(["success" => false, "message" => "All fields are required"]);
    }

} elseif ($action == "update") {
    $id = intval($data["id"] ?? 0);
    $date = trim($data["date"] ?? "");
    $meal_type = trim($data["meal_type"] ?? "");
    $description = trim($data["description"] ?? "");

    if ($id > 0 && !empty($date) && !empty($meal_type) && !empty($description)) {
        $stmt = $conn->prepare("UPDATE menu SET date=?, meal_type=?, description=? WHERE id=?");
        $stmt->bind_param("sssi", $date, $meal_type, $description, $id);
        $success = $stmt->execute();
        echo json_encode(["success" => $success]);
    } else {
        echo json_encode(["success" => false, "message" => "All fields are required"]);
    }

} elseif ($action === "delete") {
    $id = intval($data["id"] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare("DELETE FROM menu WHERE id=?");
        $stmt->bind_param("i", $id);
        $success = $stmt->execute();
        echo json_encode(["success" => $success]);
    } else {
        echo json_encode(["success" => false, "message" => "Invalid ID"]);
    }

} else {
    echo json_encode(["success" => false, "message" => "Invalid action"]);
}

$conn->close();

?>