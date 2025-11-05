<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

require_once 'config.php';

class DashboardData {
    private $conn;
    
    public function __construct($conn) {
        $this->conn = $conn;
    }
    
    public function getDashboardData($user_id) {
        $response = array();
        
        try {
            // Get user room assignment status and details
            $user_room_info = $this->getUserRoomInfo($user_id);
            
            // Get pending complaints count
            $pending_complaints = $this->getPendingComplaintsCount($user_id);
            
            // Get upcoming payments count
            $upcoming_payments = $this->getUpcomingPaymentsCount($user_id);
            
            // Get next payment date and amount
            $payment_info = $this->getNextPaymentInfo($user_id);
            
            // Get today's menu
            $todays_menu = $this->getTodaysMenu();
            
            // Get user notifications
            $notifications = $this->getUserNotifications($user_id);
            
            $response = array(
                'status' => 'success',
                'pending_complaints' => $pending_complaints,
                'upcoming_payments' => $upcoming_payments,
                'next_payment_date' => $payment_info['next_payment_date'],
                'payment_amount' => $payment_info['payment_amount'],
                'todays_menu' => $todays_menu,
                'notifications' => $notifications,
                'user_has_room' => $user_room_info['has_room'],
                'user_room_details' => $user_room_info['room_details']
            );
            
        } catch (Exception $e) {
            $response = array(
                'status' => 'error',
                'message' => 'Failed to fetch dashboard data: ' . $e->getMessage()
            );
        }
        
        return $response;
    }
    
    private function getUserRoomInfo($user_id) {
        $sql = "SELECT u.room_id, r.type, r.floor, r.rent, rm.name 
                FROM users u 
                LEFT JOIN rooms r ON u.room_id = r.room_id 
                LEFT JOIN room_members rm ON u.id = rm.user_id 
                WHERE u.id = ? AND u.status = 'active'";
        
        if ($stmt = $this->conn->prepare($sql)) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $stmt->close();
                return array(
                    'has_room' => true,
                    'room_details' => array(
                        'room_id' => $row['room_id'],
                        'type' => $row['type'],
                        'floor' => $row['floor'],
                        'rent' => $row['rent'],
                        'name' => $row['name']
                    )
                );
            }
            $stmt->close();
        }
        
        return array(
            'has_room' => false,
            'room_details' => null
        );
    }
    
    private function getPendingComplaintsCount($user_id) {
        $sql = "SELECT COUNT(*) as count FROM complaints 
                WHERE user_id = ? AND status = 'Pending'";
        
        if ($stmt = $this->conn->prepare($sql)) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            
            return $row['count'] ?? 0;
        }
        
        return 0;
    }
    
    private function getUpcomingPaymentsCount($user_id) {
        $current_date = date('Y-m-d');
        $next_week = date('Y-m-d', strtotime('+7 days'));
        
        $sql = "SELECT COUNT(*) as count FROM rent_payments 
                WHERE user_id = ? AND due_date BETWEEN ? AND ? AND status = 'Pending'";
        
        if ($stmt = $this->conn->prepare($sql)) {
            $stmt->bind_param("iss", $user_id, $current_date, $next_week);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            
            return $row['count'] ?? 0;
        }
        
        return 0;
    }
    
    private function getNextPaymentInfo($user_id) {
        $current_date = date('Y-m-d');
        
        // Get the next pending payment
        $sql = "SELECT due_date, amount FROM rent_payments 
                WHERE user_id = ? AND due_date >= ? AND status = 'Pending' 
                ORDER BY due_date ASC LIMIT 1";
        
        if ($stmt = $this->conn->prepare($sql)) {
            $stmt->bind_param("is", $user_id, $current_date);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $stmt->close();
                return array(
                    'next_payment_date' => date('d M Y', strtotime($row['due_date'])),
                    'payment_amount' => $row['amount']
                );
            }
            $stmt->close();
        }
        
        // Get user's room rent as fallback if they have a room assigned
        $sql = "SELECT r.rent FROM users u 
                JOIN rooms r ON u.room_id = r.room_id 
                WHERE u.id = ? AND u.status = 'active'";
        
        if ($stmt = $this->conn->prepare($sql)) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $stmt->close();
                // Calculate next payment date (assuming payments are due on 5th of each month)
                $next_payment_date = date('d M Y', strtotime('+1 month', strtotime(date('Y-m-05'))));
                
                return array(
                    'next_payment_date' => $next_payment_date,
                    'payment_amount' => $row['rent'] ?? '0'
                );
            }
            $stmt->close();
        }
        
        return array(
            'next_payment_date' => 'Not assigned',
            'payment_amount' => '0'
        );
    }
    
    private function getTodaysMenu() {
        $today = date('Y-m-d');
        
        // Get today's menu from the menu table
        $sql = "SELECT meal_type, description FROM menu 
                WHERE date = ? 
                ORDER BY FIELD(meal_type, 'Breakfast', 'Lunch', 'Dinner')";
        
        if ($stmt = $this->conn->prepare($sql)) {
            $stmt->bind_param("s", $today);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $menu_items = array();
            
            while ($row = $result->fetch_assoc()) {
                $meal_type = strtolower($row['meal_type']);
                $menu_items[$meal_type] = $row['description'];
            }
            $stmt->close();
            
            // Ensure all meal types are present in the response
            $required_meals = ['breakfast', 'lunch', 'dinner'];
            foreach ($required_meals as $meal) {
                if (!isset($menu_items[$meal])) {
                    $menu_items[$meal] = 'Not available';
                }
            }
            
            return $menu_items;
        }
        
        // Return default menu if query fails
        return array(
            'breakfast' => 'Not available',
            'lunch' => 'Not available',
            'dinner' => 'Not available'
        );
    }
    
    private function getUserNotifications($user_id) {
        $notifications = array();
        
        // Check for pending complaints
        $pending_complaints = $this->getPendingComplaintsCount($user_id);
        
        if ($pending_complaints > 0) {
            $notifications[] = array(
                'title' => 'Complaint Status',
                'message' => "You have {$pending_complaints} pending complaint(s)",
                'time' => 'Today'
            );
        }
        
        // Check for upcoming payments
        $current_date = date('Y-m-d');
        $next_week = date('Y-m-d', strtotime('+7 days'));
        
        $sql = "SELECT COUNT(*) as count, MIN(due_date) as next_due 
                FROM rent_payments 
                WHERE user_id = ? AND due_date BETWEEN ? AND ? AND status = 'Pending'";
        
        if ($stmt = $this->conn->prepare($sql)) {
            $stmt->bind_param("iss", $user_id, $current_date, $next_week);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            
            if ($row['count'] > 0) {
                $due_date = date('d M', strtotime($row['next_due']));
                $notifications[] = array(
                    'title' => 'Payment Reminder',
                    'message' => "You have {$row['count']} payment(s) due soon. Next due: {$due_date}",
                    'time' => 'Today'
                );
            }
        }
        
        // Check for overdue payments
        $sql = "SELECT COUNT(*) as count FROM rent_payments 
                WHERE user_id = ? AND due_date < ? AND status = 'Pending'";
        
        if ($stmt = $this->conn->prepare($sql)) {
            $stmt->bind_param("is", $user_id, $current_date);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            
            if ($row['count'] > 0) {
                $notifications[] = array(
                    'title' => 'Overdue Payment',
                    'message' => "You have {$row['count']} overdue payment(s). Please pay immediately.",
                    'time' => 'Today'
                );
            }
        }
        
        // Add welcome notification if no other notifications
        if (empty($notifications)) {
            $notifications[] = array(
                'title' => 'Welcome to Cozy Corner!',
                'message' => 'We are happy to have you here. Have a comfortable stay.',
                'time' => 'Today'
            );
        }
        
        return $notifications;
    }
}

// Handle the request
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $user_id = $input['user_id'] ?? null;
    
    if ($user_id) {
        $dashboard = new DashboardData($conn);
        $result = $dashboard->getDashboardData($user_id);
        echo json_encode($result);
    } else {
        echo json_encode(array(
            'status' => 'error', 
            'message' => 'User ID is required'
        ));
    }
} else if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    // For testing purposes, you can use GET with user_id parameter
    $user_id = $_GET['user_id'] ?? null;
    
    if ($user_id) {
        $dashboard = new DashboardData($conn);
        $result = $dashboard->getDashboardData($user_id);
        echo json_encode($result);
    } else {
        echo json_encode(array(
            'status' => 'error', 
            'message' => 'User ID is required. Use: ?user_id=2'
        ));
    }
} else {
    echo json_encode(array(
        'status' => 'error', 
        'message' => 'Invalid request method'
    ));
}

// Close connection
if (isset($conn)) {
    $conn->close();
}
?>