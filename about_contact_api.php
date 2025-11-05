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

// Get the action parameter
$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    case 'get_pg_info':
        getPGInfo();
        break;
    
    case 'get_emergency_contacts':
        getEmergencyContacts();
        break;
    
    case 'get_contact_responses':
        getContactResponses();
        break;
    
    case 'submit_contact':
        submitContact();
        break;
    
    case 'mark_message_read':
        markMessageRead();
        break;
    
    case 'mark_all_read':
        markAllRead();
        break;
    
    default:
        echo json_encode([
            "success" => false,
            "message" => "Invalid action"
        ]);
        break;
}

function getPGInfo() {
    global $conn;
    
    try {
        // Check if pg_info table exists and has data
        $sql = "SELECT name, owner_name, contact, address, description, latitude, longitude 
                FROM pg_info 
                ORDER BY id DESC 
                LIMIT 1";
        $result = $conn->query($sql);
        
        if ($result && $result->num_rows > 0) {
            $pg_info = $result->fetch_assoc();
        } else {
            // Fallback to static data if table doesn't exist or is empty
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

function getEmergencyContacts() {
    global $conn;
    
    try {
        $sql = "SELECT name, phone_number, type FROM emergency_contacts WHERE is_active = 1 ORDER BY type, name";
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

function getContactResponses() {
    global $conn;
    
    try {
        $user_email = isset($_GET['email']) ? trim($_GET['email']) : '';
        
        if (empty($user_email)) {
            echo json_encode([
                "success" => false,
                "message" => "Email is required"
            ]);
            return;
        }
        
        // Check if contact_responses table exists
        $tableExists = $conn->query("SHOW TABLES LIKE 'contact_responses'");
        
        if ($tableExists && $tableExists->num_rows > 0) {
            // Get actual admin responses with contact message context
            $sql = "SELECT 
                        cr.id,
                        cr.contact_id,
                        cr.subject,
                        cr.message as response_message,
                        cr.is_read,
                        cr.created_at,
                        cr.read_at,
                        a.name as admin_name,
                        c.message as original_message,
                        c.user_name
                    FROM contact_responses cr
                    JOIN contacts c ON cr.contact_id = c.id
                    LEFT JOIN users a ON cr.admin_id = a.id
                    WHERE c.email = ? 
                    ORDER BY cr.created_at DESC 
                    LIMIT 20";
                    
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $user_email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $messages = [];
            while ($row = $result->fetch_assoc()) {
                $messages[] = [
                    'id' => $row['id'],
                    'contact_id' => $row['contact_id'],
                    'admin_name' => $row['admin_name'] ?? 'PG Manager',
                    'subject' => $row['subject'] ?? 'Response to your message',
                    'message' => $row['response_message'],
                    'original_message' => $row['original_message'],
                    'user_name' => $row['user_name'],
                    'is_read' => $row['is_read'],
                    'created_at' => $row['created_at'],
                    'type' => 'response'
                ];
            }
            $stmt->close();
            
            // Also include user's own contact messages for context
            $userMessagesSql = "SELECT 
                                id,
                                user_name,
                                email,
                                message,
                                created_at,
                                '1' as is_read,
                                'Your contact message' as subject,
                                'You' as admin_name
                            FROM contacts 
                            WHERE email = ? 
                            ORDER BY created_at DESC 
                            LIMIT 10";
                            
            $userStmt = $conn->prepare($userMessagesSql);
            $userStmt->bind_param("s", $user_email);
            $userStmt->execute();
            $userResult = $userStmt->get_result();
            
            while ($row = $userResult->fetch_assoc()) {
                $messages[] = [
                    'id' => 'user_' . $row['id'],
                    'contact_id' => $row['id'],
                    'admin_name' => $row['admin_name'],
                    'subject' => $row['subject'],
                    'message' => $row['message'],
                    'is_read' => $row['is_read'],
                    'created_at' => $row['created_at'],
                    'type' => 'user_message'
                ];
            }
            $userStmt->close();
            
            // Sort all messages by date
            usort($messages, function($a, $b) {
                return strtotime($b['created_at']) - strtotime($a['created_at']);
            });
            
        } else {
            // Fallback to showing user's own contact messages
            $sql = "SELECT 
                        id,
                        user_name,
                        email,
                        message,
                        created_at,
                        '1' as is_read,
                        'Your contact message' as subject,
                        'PG Manager' as admin_name
                    FROM contacts 
                    WHERE email = ? 
                    ORDER BY created_at DESC 
                    LIMIT 10";
                    
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $user_email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $messages = [];
            while ($row = $result->fetch_assoc()) {
                $messages[] = [
                    'id' => $row['id'],
                    'contact_id' => $row['id'],
                    'admin_name' => $row['admin_name'] ?? 'PG Manager',
                    'subject' => $row['subject'] ?? 'Your contact message',
                    'message' => $row['message'],
                    'is_read' => $row['is_read'],
                    'created_at' => $row['created_at'],
                    'type' => 'user_message'
                ];
            }
            $stmt->close();
        }
        
        echo json_encode([
            "success" => true,
            "messages" => $messages,
            "total" => count($messages)
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Failed to fetch contact responses",
            "error" => $e->getMessage()
        ]);
    }
}

function submitContact() {
    global $conn;
    
    try {
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
            return;
        }
        
        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode([
                "success" => false,
                "message" => "Please enter a valid email address"
            ]);
            return;
        }
        
        // Check for spam/database injection
        if (strlen($message) > 1000) {
            echo json_encode([
                "success" => false,
                "message" => "Message is too long. Please keep it under 1000 characters."
            ]);
            return;
        }

        // FIXED: Include status field in insert
        $stmt = $conn->prepare("INSERT INTO contacts (user_name, email, message, status) VALUES (?, ?, ?, 'pending')");

        if (!$stmt) {
            echo json_encode([
                "success" => false,
                "message" => "Database error. Please try again.",
                "error" => $conn->error
            ]);
            return;
        }

        $stmt->bind_param("sss", $name, $email, $message);

        if ($stmt->execute()) {
            echo json_encode([
                "success" => true,
                "message" => "Thank you for your message! We will get back to you soon."
            ]);
        } else {
            echo json_encode([
                "success" => false,
                "message" => "Failed to send message. Please try again.",
                "error" => $stmt->error
            ]);
        }

        $stmt->close();
        
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "An error occurred. Please try again.",
            "error" => $e->getMessage()
        ]);
    }
}

function markMessageRead() {
    global $conn;
    
    try {
        $rawInput = file_get_contents("php://input");
        $data = json_decode($rawInput, true);
        
        $message_id = isset($data['message_id']) ? trim($data['message_id']) : '';
        $user_email = isset($data['email']) ? trim($data['email']) : '';
        
        if (empty($message_id) || empty($user_email)) {
            echo json_encode([
                "success" => false,
                "message" => "Message ID and email are required"
            ]);
            return;
        }
        
        // Check if contact_responses table exists
        $tableExists = $conn->query("SHOW TABLES LIKE 'contact_responses'");
        
        if ($tableExists && $tableExists->num_rows > 0) {
            // Update read status in contact_responses table
            $sql = "UPDATE contact_responses cr
                    JOIN contacts c ON cr.contact_id = c.id
                    SET cr.is_read = 1, cr.read_at = NOW()
                    WHERE cr.id = ? AND c.email = ?";
                    
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $message_id, $user_email);
            $stmt->execute();
            
            if ($stmt->affected_rows > 0) {
                echo json_encode([
                    "success" => true,
                    "message" => "Message marked as read"
                ]);
            } else {
                echo json_encode([
                    "success" => false,
                    "message" => "Message not found or already read"
                ]);
            }
            $stmt->close();
        } else {
            // If table doesn't exist, just return success
            echo json_encode([
                "success" => true,
                "message" => "Message marked as read"
            ]);
        }
        
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Failed to mark message as read",
            "error" => $e->getMessage()
        ]);
    }
}

function markAllRead() {
    global $conn;
    
    try {
        $rawInput = file_get_contents("php://input");
        $data = json_decode($rawInput, true);
        
        $user_email = isset($data['email']) ? trim($data['email']) : '';
        
        if (empty($user_email)) {
            echo json_encode([
                "success" => false,
                "message" => "Email is required"
            ]);
            return;
        }
        
        // Check if contact_responses table exists
        $tableExists = $conn->query("SHOW TABLES LIKE 'contact_responses'");
        
        if ($tableExists && $tableExists->num_rows > 0) {
            // Mark all messages as read for this user
            $sql = "UPDATE contact_responses cr
                    JOIN contacts c ON cr.contact_id = c.id
                    SET cr.is_read = 1, cr.read_at = NOW()
                    WHERE c.email = ? AND cr.is_read = 0";
                    
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $user_email);
            $stmt->execute();
            $affected_rows = $stmt->affected_rows;
            
            echo json_encode([
                "success" => true,
                "message" => "Marked $affected_rows messages as read"
            ]);
            
            $stmt->close();
        } else {
            // If table doesn't exist, just return success
            echo json_encode([
                "success" => true,
                "message" => "All messages marked as read"
            ]);
        }
        
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => "Failed to mark messages as read",
            "error" => $e->getMessage()
        ]);
    }
}

$conn->close();
?>