<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE");
header("Content-Type: application/json");

require 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    case 'get_all_contacts':
        getAllContacts();
        break;
    
    case 'get_contact_details':
        getContactDetails();
        break;
    
    case 'add_response':
        addResponse();
        break;
    
    case 'update_pg_info':
        updatePGInfo();
        break;
    
    case 'get_pg_info_admin':
        getPGInfoAdmin();
        break;
    
    case 'get_emergency_contacts':
        getEmergencyContactsAdmin();
        break;
    
    case 'update_emergency_contacts':
        updateEmergencyContacts();
        break;
    
    case 'add_emergency_contact':
        addEmergencyContact();
        break;
    
    case 'update_emergency_contact':
        updateEmergencyContact();
        break;
    
    case 'delete_emergency_contact':
        deleteEmergencyContact();
        break;
    
    case 'get_contact_stats':
        getContactStats();
        break;
    
    default:
        echo json_encode([
            "success" => false,
            "message" => "Invalid action"
        ]);
        break;
}

function getAllContacts() {
    global $conn;
    
    try {
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
        $status = isset($_GET['status']) ? $_GET['status'] : 'all';
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';
        $offset = ($page - 1) * $limit;
        
        // Build WHERE clause
        $whereConditions = [];
        $params = [];
        $types = "";
        
        // Status filtering
        if ($status === 'pending') {
            $whereConditions[] = "c.status = 'pending'";
        } elseif ($status === 'replied') {
            $whereConditions[] = "c.status = 'replied'";
        }
        
        // Search filtering
        if (!empty($search)) {
            $whereConditions[] = "(c.user_name LIKE ? OR c.email LIKE ? OR c.message LIKE ?)";
            $searchTerm = "%$search%";
            $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
            $types .= "sss";
        }
        
        // Combine WHERE conditions
        $whereClause = "";
        if (!empty($whereConditions)) {
            $whereClause = "WHERE " . implode(" AND ", $whereConditions);
        }
        
        // Main query to get contacts
        $sql = "SELECT 
                    c.*,
                    COUNT(cr.id) as response_count,
                    MAX(cr.created_at) as last_response_date
                FROM contacts c
                LEFT JOIN contact_responses cr ON c.id = cr.contact_id
                $whereClause
                GROUP BY c.id
                ORDER BY c.created_at DESC
                LIMIT ? OFFSET ?";
        
        $params = array_merge($params, [$limit, $offset]);
        $types .= "ii";
        
        $stmt = $conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        } else {
            $stmt->bind_param("ii", $limit, $offset);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        $contacts = [];
        while ($row = $result->fetch_assoc()) {
            $contacts[] = $row;
        }
        
        // Count query for pagination
        $countSql = "SELECT COUNT(*) as total FROM contacts c $whereClause";
        $countStmt = $conn->prepare($countSql);
        
        if (!empty($whereConditions)) {
            $countParams = array_slice($params, 0, count($params) - 2);
            $countTypes = substr($types, 0, -2);
            if (!empty($countParams)) {
                $countStmt->bind_param($countTypes, ...$countParams);
            }
        }
        
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $totalRow = $countResult->fetch_assoc();
        $total = $totalRow['total'];
        
        echo json_encode([
            "success" => true,
            "contacts" => $contacts,
            "total" => $total,
            "page" => $page,
            "totalPages" => ceil($total / $limit)
        ]);
        
        $stmt->close();
        if (isset($countStmt)) $countStmt->close();
        
    } catch (Exception $e) {
        error_log("Error in getAllContacts: " . $e->getMessage());
        echo json_encode([
            "success" => false,
            "message" => "Failed to fetch contacts",
            "error" => $e->getMessage()
        ]);
    }
}

function getContactStats() {
    global $conn;
    
    try {
        // Total contacts
        $totalSql = "SELECT COUNT(*) as total FROM contacts";
        $totalResult = $conn->query($totalSql);
        $total = $totalResult ? $totalResult->fetch_assoc()['total'] : 0;
        
        // Pending contacts
        $pendingSql = "SELECT COUNT(*) as pending FROM contacts WHERE status = 'pending'";
        $pendingResult = $conn->query($pendingSql);
        $pending = $pendingResult ? $pendingResult->fetch_assoc()['pending'] : 0;
        
        // Replied contacts
        $repliedSql = "SELECT COUNT(*) as replied FROM contacts WHERE status = 'replied'";
        $repliedResult = $conn->query($repliedSql);
        $replied = $repliedResult ? $repliedResult->fetch_assoc()['replied'] : 0;
        
        // Today's contacts
        $todaySql = "SELECT COUNT(*) as today FROM contacts WHERE DATE(created_at) = CURDATE()";
        $todayResult = $conn->query($todaySql);
        $today = $todayResult ? $todayResult->fetch_assoc()['today'] : 0;
        
        // This week's contacts
        $weekSql = "SELECT COUNT(*) as week FROM contacts WHERE YEARWEEK(created_at) = YEARWEEK(CURDATE())";
        $weekResult = $conn->query($weekSql);
        $week = $weekResult ? $weekResult->fetch_assoc()['week'] : 0;
        
        echo json_encode([
            "success" => true,
            "stats" => [
                "total" => $total,
                "pending" => $pending,
                "replied" => $replied,
                "today" => $today,
                "this_week" => $week
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Error in getContactStats: " . $e->getMessage());
        echo json_encode([
            "success" => false,
            "message" => "Failed to fetch contact statistics",
            "error" => $e->getMessage()
        ]);
    }
}

function getContactDetails() {
    global $conn;
    
    try {
        $contact_id = isset($_GET['contact_id']) ? intval($_GET['contact_id']) : 0;
        
        if ($contact_id <= 0) {
            echo json_encode([
                "success" => false,
                "message" => "Invalid contact ID"
            ]);
            return;
        }
        
        $contactSql = "SELECT * FROM contacts WHERE id = ?";
        $contactStmt = $conn->prepare($contactSql);
        $contactStmt->bind_param("i", $contact_id);
        $contactStmt->execute();
        $contactResult = $contactStmt->get_result();
        $contact = $contactResult->fetch_assoc();
        
        if (!$contact) {
            echo json_encode([
                "success" => false,
                "message" => "Contact not found"
            ]);
            return;
        }
        
        $responseSql = "SELECT 
                            cr.*,
                            u.name as admin_name
                        FROM contact_responses cr
                        LEFT JOIN users u ON cr.admin_id = u.id
                        WHERE cr.contact_id = ?
                        ORDER BY cr.created_at ASC";
        $responseStmt = $conn->prepare($responseSql);
        $responseStmt->bind_param("i", $contact_id);
        $responseStmt->execute();
        $responseResult = $responseStmt->get_result();
        
        $responses = [];
        while ($row = $responseResult->fetch_assoc()) {
            $responses[] = $row;
        }
        
        echo json_encode([
            "success" => true,
            "contact" => $contact,
            "responses" => $responses
        ]);
        
        $contactStmt->close();
        $responseStmt->close();
        
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Failed to fetch contact details",
            "error" => $e->getMessage()
        ]);
    }
}

function addResponse() {
    global $conn;
    
    try {
        $rawInput = file_get_contents("php://input");
        $data = json_decode($rawInput, true);
        
        $contact_id = isset($data['contact_id']) ? intval($data['contact_id']) : 0;
        $admin_id = isset($data['admin_id']) ? intval($data['admin_id']) : 0;
        $subject = isset($data['subject']) ? trim($data['subject']) : 'Response to your message';
        $message = isset($data['message']) ? trim($data['message']) : '';
        
        if ($contact_id <= 0 || empty($message)) {
            echo json_encode([
                "success" => false,
                "message" => "Contact ID and message are required"
            ]);
            return;
        }
        
        $conn->begin_transaction();
        
        try {
            $sql = "INSERT INTO contact_responses (contact_id, admin_id, subject, message) VALUES (?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iiss", $contact_id, $admin_id, $subject, $message);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to add response: " . $stmt->error);
            }
            
            $updateSql = "UPDATE contacts SET status = 'replied' WHERE id = ?";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bind_param("i", $contact_id);
            
            if (!$updateStmt->execute()) {
                throw new Exception("Failed to update contact status: " . $updateStmt->error);
            }
            
            $conn->commit();
            
            echo json_encode([
                "success" => true,
                "message" => "Response added successfully",
                "response_id" => $stmt->insert_id
            ]);
            
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
        
        $stmt->close();
        if (isset($updateStmt)) $updateStmt->close();
        
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Failed to add response",
            "error" => $e->getMessage()
        ]);
    }
}

function updatePGInfo() {
    global $conn;
    
    try {
        $rawInput = file_get_contents("php://input");
        $data = json_decode($rawInput, true);
        
        $name = isset($data['name']) ? trim($data['name']) : '';
        $owner_name = isset($data['owner_name']) ? trim($data['owner_name']) : '';
        $contact = isset($data['contact']) ? trim($data['contact']) : '';
        $address = isset($data['address']) ? trim($data['address']) : '';
        $description = isset($data['description']) ? trim($data['description']) : '';
        $latitude = isset($data['latitude']) ? floatval($data['latitude']) : null;
        $longitude = isset($data['longitude']) ? floatval($data['longitude']) : null;
        
        if (empty($name) || empty($owner_name) || empty($contact) || empty($address)) {
            echo json_encode([
                "success" => false,
                "message" => "All fields are required"
            ]);
            return;
        }
        
        $checkSql = "SELECT id FROM pg_info LIMIT 1";
        $checkResult = $conn->query($checkSql);
        
        if ($checkResult && $checkResult->num_rows > 0) {
            $sql = "UPDATE pg_info SET 
                    name = ?, owner_name = ?, contact = ?, address = ?, 
                    description = ?, latitude = ?, longitude = ?,
                    updated_at = NOW()
                    WHERE id = (SELECT id FROM (SELECT id FROM pg_info LIMIT 1) as temp)";
        } else {
            $sql = "INSERT INTO pg_info (name, owner_name, contact, address, description, latitude, longitude) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
        }
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssssdd", $name, $owner_name, $contact, $address, $description, $latitude, $longitude);
        
        if ($stmt->execute()) {
            echo json_encode([
                "success" => true,
                "message" => "PG information updated successfully"
            ]);
        } else {
            echo json_encode([
                "success" => false,
                "message" => "Failed to update PG information",
                "error" => $stmt->error
            ]);
        }
        
        $stmt->close();
        
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Failed to update PG information",
            "error" => $e->getMessage()
        ]);
    }
}

function getPGInfoAdmin() {
    global $conn;
    
    try {
        $sql = "SELECT * FROM pg_info ORDER BY id DESC LIMIT 1";
        $result = $conn->query($sql);
        
        if ($result && $result->num_rows > 0) {
            $pg_info = $result->fetch_assoc();
        } else {
            $pg_info = [
                "name" => "Cozy Corner PG",
                "owner_name" => "Mr. Rajesh Kumar",
                "contact" => "+91 9876543210",
                "address" => "Near XYZ Road, Ahmedabad, Gujarat",
                "description" => "A safe, affordable, and comfortable stay with daily meals, laundry, and housekeeping.",
                "latitude" => "23.0225",
                "longitude" => "72.5714"
            ];
        }
        
        echo json_encode([
            "success" => true,
            "data" => $pg_info
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Failed to fetch PG info",
            "error" => $e->getMessage()
        ]);
    }
}

function getEmergencyContactsAdmin() {
    global $conn;
    
    try {
        $sql = "SELECT id, name, phone_number, type, is_active FROM emergency_contacts WHERE is_active = 1 ORDER BY type, name";
        $result = $conn->query($sql);
        
        $contacts = [];
        if ($result->num_rows > 0) {
            while($row = $result->fetch_assoc()) {
                $contacts[] = $row;
            }
        }
        
        echo json_encode([
            "success" => true,
            "contacts" => $contacts
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Failed to fetch emergency contacts",
            "error" => $e->getMessage()
        ]);
    }
}

function addEmergencyContact() {
    global $conn;
    
    try {
        $rawInput = file_get_contents("php://input");
        $data = json_decode($rawInput, true);
        
        $name = isset($data['name']) ? trim($data['name']) : '';
        $phone_number = isset($data['phone_number']) ? trim($data['phone_number']) : '';
        $type = isset($data['type']) ? trim($data['type']) : 'general';
        
        if (empty($name) || empty($phone_number)) {
            echo json_encode([
                "success" => false,
                "message" => "Name and phone number are required"
            ]);
            return;
        }
        
        $sql = "INSERT INTO emergency_contacts (name, phone_number, type, is_active) VALUES (?, ?, ?, 1)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sss", $name, $phone_number, $type);
        
        if ($stmt->execute()) {
            echo json_encode([
                "success" => true,
                "message" => "Emergency contact added successfully",
                "contact_id" => $stmt->insert_id
            ]);
        } else {
            echo json_encode([
                "success" => false,
                "message" => "Failed to add emergency contact",
                "error" => $stmt->error
            ]);
        }
        
        $stmt->close();
        
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Failed to add emergency contact",
            "error" => $e->getMessage()
        ]);
    }
}

function updateEmergencyContact() {
    global $conn;
    
    try {
        $rawInput = file_get_contents("php://input");
        $data = json_decode($rawInput, true);
        
        // Debug: Log received data
        error_log("Update Contact Data: " . json_encode($data));
        
        $id = isset($data['id']) ? intval($data['id']) : 0;
        $name = isset($data['name']) ? trim($data['name']) : '';
        $phone_number = isset($data['phone_number']) ? trim($data['phone_number']) : '';
        $type = isset($data['type']) ? trim($data['type']) : 'general';
        
        if ($id <= 0 || empty($name) || empty($phone_number)) {
            echo json_encode([
                "success" => false,
                "message" => "Valid ID, name and phone number are required",
                "debug" => ["id" => $id, "name" => $name, "phone" => $phone_number]
            ]);
            return;
        }
        
        // Check if contact exists
        $checkSql = "SELECT id FROM emergency_contacts WHERE id = ? AND is_active = 1";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("i", $id);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult->num_rows === 0) {
            echo json_encode([
                "success" => false,
                "message" => "Contact not found or already deleted"
            ]);
            $checkStmt->close();
            return;
        }
        $checkStmt->close();
        
        $sql = "UPDATE emergency_contacts SET name = ?, phone_number = ?, type = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssi", $name, $phone_number, $type, $id);
        
        if ($stmt->execute()) {
            echo json_encode([
                "success" => true,
                "message" => "Emergency contact updated successfully",
                "debug" => ["affected_rows" => $stmt->affected_rows]
            ]);
        } else {
            echo json_encode([
                "success" => false,
                "message" => "Failed to update emergency contact",
                "error" => $stmt->error
            ]);
        }
        
        $stmt->close();
        
    } catch (Exception $e) {
        error_log("Update Contact Error: " . $e->getMessage());
        echo json_encode([
            "success" => false,
            "message" => "Failed to update emergency contact",
            "error" => $e->getMessage()
        ]);
    }
}

function deleteEmergencyContact() {
    global $conn;
    
    try {
        $rawInput = file_get_contents("php://input");
        $data = json_decode($rawInput, true);
        
        // Debug: Log received data
        error_log("Delete Contact Data: " . json_encode($data));
        
        $id = isset($data['id']) ? intval($data['id']) : 0;
        
        if ($id <= 0) {
            echo json_encode([
                "success" => false,
                "message" => "Valid ID is required",
                "debug" => ["id" => $id]
            ]);
            return;
        }
        
        // Check if contact exists
        $checkSql = "SELECT id FROM emergency_contacts WHERE id = ? AND is_active = 1";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("i", $id);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult->num_rows === 0) {
            echo json_encode([
                "success" => false,
                "message" => "Contact not found or already deleted"
            ]);
            $checkStmt->close();
            return;
        }
        $checkStmt->close();
        
        // Soft delete by setting is_active to 0
        $sql = "UPDATE emergency_contacts SET is_active = 0 WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            echo json_encode([
                "success" => true,
                "message" => "Emergency contact deleted successfully",
                "debug" => ["affected_rows" => $stmt->affected_rows]
            ]);
        } else {
            echo json_encode([
                "success" => false,
                "message" => "Failed to delete emergency contact",
                "error" => $stmt->error
            ]);
        }
        
        $stmt->close();
        
    } catch (Exception $e) {
        error_log("Delete Contact Error: " . $e->getMessage());
        echo json_encode([
            "success" => false,
            "message" => "Failed to delete emergency contact",
            "error" => $e->getMessage()
        ]);
    }
}

function updateEmergencyContacts() {
    global $conn;
    
    try {
        $rawInput = file_get_contents("php://input");
        $data = json_decode($rawInput, true);
        
        $contacts = isset($data['contacts']) ? $data['contacts'] : [];
        
        if (empty($contacts)) {
            echo json_encode([
                "success" => false,
                "message" => "No contacts provided"
            ]);
            return;
        }
        
        $conn->begin_transaction();
        
        try {
            // Deactivate all contacts first
            $deactivateSql = "UPDATE emergency_contacts SET is_active = 0";
            $conn->query($deactivateSql);
            
            foreach ($contacts as $contact) {
                $name = $contact['name'] ?? '';
                $phone_number = $contact['phone_number'] ?? '';
                $type = $contact['type'] ?? 'general';
                
                if (!empty($name) && !empty($phone_number)) {
                    $checkSql = "SELECT id FROM emergency_contacts WHERE name = ? AND type = ?";
                    $checkStmt = $conn->prepare($checkSql);
                    $checkStmt->bind_param("ss", $name, $type);
                    $checkStmt->execute();
                    $checkResult = $checkStmt->get_result();
                    
                    if ($checkResult->num_rows > 0) {
                        $updateSql = "UPDATE emergency_contacts SET phone_number = ?, is_active = 1 WHERE name = ? AND type = ?";
                        $updateStmt = $conn->prepare($updateSql);
                        $updateStmt->bind_param("sss", $phone_number, $name, $type);
                        $updateStmt->execute();
                        $updateStmt->close();
                    } else {
                        $insertSql = "INSERT INTO emergency_contacts (name, phone_number, type, is_active) VALUES (?, ?, ?, 1)";
                        $insertStmt = $conn->prepare($insertSql);
                        $insertStmt->bind_param("sss", $name, $phone_number, $type);
                        $insertStmt->execute();
                        $insertStmt->close();
                    }
                    
                    $checkStmt->close();
                }
            }
            
            $conn->commit();
            
            echo json_encode([
                "success" => true,
                "message" => "Emergency contacts updated successfully"
            ]);
            
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
        
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Failed to update emergency contacts",
            "error" => $e->getMessage()
        ]);
    }
}

$conn->close();
?>