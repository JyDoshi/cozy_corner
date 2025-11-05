<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require 'config.php';

// Only handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Check if all required fields are present
    if (!isset($input['name']) || !isset($input['email']) || !isset($input['password']) || !isset($input['contact'])) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "All fields are required"]);
        exit();
    }

    // Get and sanitize input data
    $name = $conn->real_escape_string(trim($input['name']));
    $email = $conn->real_escape_string(trim($input['email']));
    $password = $conn->real_escape_string(trim($input['password']));
    $contact = $conn->real_escape_string(trim($input['contact']));
    $role = isset($input['role']) ? $conn->real_escape_string(trim($input['role'])) : 'User';
    $status = 'inactive';
    
    // Set room_id and image as NULL
    $room_id = NULL;
    $image = NULL;

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(["success" => false, "message" => "Invalid email format"]);
        exit();
    }

    // Validate contact number (10 digits)
    if (!preg_match('/^[0-9]{10}$/', $contact)) {
        echo json_encode(["success" => false, "message" => "Contact number must be exactly 10 digits"]);
        exit();
    }

    // Check if email already exists
    $checkEmailQuery = "SELECT id FROM users WHERE email = ?";
    $stmt = $conn->prepare($checkEmailQuery);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $stmt->close();
        echo json_encode(["success" => false, "message" => "Email already exists. Please use a different email."]);
        exit();
    }
    $stmt->close();

    // Insert user into database with NULL room_id and image
    $sql = "INSERT INTO users (name, email, password, contact, room_id, role, status, image) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssssisss", $name, $email, $password, $contact, $room_id, $role, $status, $image);

    if ($stmt->execute()) {
        $user_id = $stmt->insert_id;
        
        // Get the inserted user data
        $selectQuery = "SELECT id, name, email, contact, room_id, role, status, image FROM users WHERE id = ?";
        $selectStmt = $conn->prepare($selectQuery);
        $selectStmt->bind_param("i", $user_id);
        $selectStmt->execute();
        $userResult = $selectStmt->get_result();
        $user = $userResult->fetch_assoc();
        
        echo json_encode([
            "success" => true, 
            "message" => "Registration successful",
            "user" => $user
        ]);
        
        $selectStmt->close();
    } else {
        echo json_encode(["success" => false, "message" => "Registration failed: " . $stmt->error]);
    }
    
    $stmt->close();
    
} else {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
}

$conn->close();
?>