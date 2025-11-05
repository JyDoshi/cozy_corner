<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Content-Type: application/json");

require 'config.php';

/* ✅ Ensure required columns exist */
$columns = [
    "contact VARCHAR(20) NULL AFTER email",
    "room_id INT NULL AFTER contact",
    "status ENUM('active','inactive') DEFAULT 'inactive' AFTER room_id"
];

foreach ($columns as $col) {
    preg_match("/^([a-zA-Z0-9_]+)/", $col, $matches);
    $colName = $matches[1] ?? "";
    if ($colName) {
        $check = $conn->query("SHOW COLUMNS FROM users LIKE '$colName'");
        if ($check->num_rows === 0) {
            $conn->query("ALTER TABLE users ADD COLUMN $col");
        }
    }
}

$action = $_GET['action'] ?? '';

/* ✅ LIST MEMBERS BY ROOM */
if ($action == "list" && isset($_GET['room_id'])) {
    $room_id = (int) $_GET['room_id'];
    if (!$room_id) {
        echo json_encode([]);
        exit;
    }

    $stmt = $conn->prepare("
        SELECT r.room_id, r.rent, rm.member_id, rm.name, rm.contact, rm.age,rm.photo_path
        FROM room_members rm
        JOIN rooms r ON r.room_id = rm.room_id
        WHERE r.room_id = ?
    ");
    $stmt->bind_param("i", $room_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $members = [];
    while ($row = $res->fetch_assoc()) {
        $members[] = $row;
    }

    echo json_encode($members);
}

/* ✅ ADD NEW MEMBER + SYNC WITH USERS */
elseif ($action == "add") {
    $data = json_decode(file_get_contents("php://input"), true);
    $name = trim($data['name'] ?? '');
    $age = trim($data['age'] ?? '');
    $contact = trim($data['contact'] ?? '');
    $room_id = (int) ($data['room_id'] ?? 0);

    if (!$name || !$contact || !$room_id) {
        echo json_encode(["success" => false, "message" => "Missing fields"]);
        exit;
    }

    // ✅ FIXED: First check if user exists and get user_id
    $checkUser = $conn->prepare("SELECT id FROM users WHERE name = ? AND contact = ? LIMIT 1");
    $checkUser->bind_param("ss", $name, $contact);
    $checkUser->execute();
    $res = $checkUser->get_result();

    $user_id = null;

    if ($res->num_rows > 0) {
        // ✅ User exists → get user_id and activate
        $userData = $res->fetch_assoc();
        $user_id = $userData['id'];
        
        $updateUser = $conn->prepare("UPDATE users SET room_id = ?, status = 'active' WHERE id = ?");
        $updateUser->bind_param("ii", $room_id, $user_id);
        $updateUser->execute();
        
    } else {
        // ✅ New user → first create user and get user_id
        $password = strtolower(str_replace(' ', '', $name));
        $role = "User";
        $status = "active";
        
        // ✅ FIXED: Generate unique email
        $baseEmail = strtolower(str_replace(' ', '', $name));
        $email = $baseEmail . "@gmail.com";
        $counter = 1;
        
        // Check if email already exists and find a unique one
        while (true) {
            $checkEmail = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $checkEmail->bind_param("s", $email);
            $checkEmail->execute();
            $emailResult = $checkEmail->get_result();
            
            if ($emailResult->num_rows === 0) {
                break; // Email is unique, break the loop
            }
            
            // Email exists, try with counter
            $email = $baseEmail . $counter . "@gmail.com";
            $counter++;
            
            // Safety check to prevent infinite loop
            if ($counter > 100) {
                echo json_encode(["success" => false, "message" => "Could not generate unique email"]);
                exit;
            }
        }
        
        $insertUser = $conn->prepare("
            INSERT INTO users (name, email, contact, room_id, role, status, password)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $insertUser->bind_param("sssisss", $name, $email, $contact, $room_id, $role, $status, $password);
        
        if ($insertUser->execute()) {
            $user_id = $insertUser->insert_id; // Get the auto-generated user_id
        } else {
            echo json_encode(["success" => false, "message" => "Failed to create user: " . $conn->error]);
            exit;
        }
    }

    // ✅ Now insert into room_members WITH the user_id
    $stmt = $conn->prepare("INSERT INTO room_members (user_id, name, contact, room_id, age) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issis", $user_id, $name, $contact, $room_id, $age);

    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "Member added and synced successfully"]);
    } else {
        echo json_encode(["success" => false, "message" => "Failed to add member: " . $conn->error]);
    }
}

/* ✅ DELETE MEMBER + DEACTIVATE USER */
elseif ($action == "delete") {
    $id = $_GET['id'] ?? '';
    if (!$id) {
        echo json_encode(["success" => false, "message" => "Missing id"]);
        exit;
    }

    // Get member details before delete
    $res = $conn->query("SELECT user_id, name, contact FROM room_members WHERE member_id = '$id' LIMIT 1");
    $member = $res ? $res->fetch_assoc() : null;

    // Delete member
    $delStmt = $conn->prepare("DELETE FROM room_members WHERE member_id = ?");
    $delStmt->bind_param("i", $id);

    if ($delStmt->execute()) {
        if ($member) {
            $user_id = $member['user_id'];
            $name = $member['name'];
            $contact = $member['contact'];
            
            // Deactivate user using user_id if available, otherwise use name/contact
            if ($user_id) {
                $update = $conn->prepare("UPDATE users SET status = 'inactive', room_id = NULL WHERE id = ?");
                $update->bind_param("i", $user_id);
                $update->execute();
            } else {
                $update = $conn->prepare("UPDATE users SET status = 'inactive', room_id = NULL WHERE name = ? AND contact = ?");
                $update->bind_param("ss", $name, $contact);
                $update->execute();
            }
        }

        echo json_encode(["success" => true, "message" => "Member deleted and user deactivated"]);
    } else {
        echo json_encode(["success" => false, "message" => $conn->error]);
    }
}

/* ✅ UPDATE MEMBER INFO + SYNC WITH USERS */
elseif ($action == "update") {
    $id = $_GET['id'] ?? '';
    $name = $_GET['name'] ?? '';
    $contact = $_GET['contact'] ?? '';
    $age = $_GET['age'] ?? '';

    if (!$id || !$name || !$contact || !$age) {
        echo json_encode(["success" => false, "message" => "Missing fields"]);
        exit;
    }

    // First get the current member details to find the user_id
    $getMember = $conn->prepare("SELECT user_id FROM room_members WHERE member_id = ?");
    $getMember->bind_param("i", $id);
    $getMember->execute();
    $memberResult = $getMember->get_result();
    $memberData = $memberResult->fetch_assoc();
    $user_id = $memberData['user_id'] ?? null;

    // Update room_members table
    $stmt = $conn->prepare("UPDATE room_members SET name = ?, contact = ?, age = ? WHERE member_id = ?");
    $stmt->bind_param("sssi", $name, $contact, $age, $id);

    if ($stmt->execute()) {
        // ✅ SYNC: Also update users table if user_id exists
        if ($user_id) {
            $updateUser = $conn->prepare("UPDATE users SET name = ?, contact = ? WHERE id = ?");
            $updateUser->bind_param("ssi", $name, $contact, $user_id);
            $updateUser->execute();
        }

        echo json_encode(["success" => true, "message" => "Member updated and synced with user table"]);
    } else {
        echo json_encode(["success" => false, "message" => $conn->error]);
    }
}

else {
    echo json_encode(["success" => false, "message" => "Invalid action"]);
}

$conn->close();
?>