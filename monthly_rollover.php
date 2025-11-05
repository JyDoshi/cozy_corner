<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

require 'config.php';

function monthlyRentRollover($conn) {
    $current_month = date('Y-m');
    $previous_month = date('Y-m', strtotime('-1 month'));
    
    $response = [
        'success' => true,
        'rollover_count' => 0,
        'new_users_count' => 0,
        'current_month' => $current_month,
        'previous_month' => $previous_month,
        'errors' => []
    ];
    
    // Get all pending/partial payments from previous month
    $sql = "SELECT rp.*, u.name, r.rent 
            FROM rent_payments rp 
            JOIN users u ON rp.user_id = u.id 
            JOIN rooms r ON rp.room_id = r.room_id 
            WHERE rp.month_year = '$previous_month' 
            AND rp.status IN ('Pending', 'Partial') 
            AND u.status = 'active'";
    
    $result = $conn->query($sql);
    
    if (!$result) {
        $response['errors'][] = "Database error: " . $conn->error;
        $response['success'] = false;
        return $response;
    }
    
    $rollover_count = 0;
    
    while ($row = $result->fetch_assoc()) {
        $remaining_balance = floatval($row['amount']) + floatval($row['late_fee']) - floatval($row['paid_amount']);
        
        if ($remaining_balance > 0) {
            // Apply late fee for carryover (10% of remaining balance or ₹100 minimum)
            $new_late_fee = max(100, $remaining_balance * 0.10);
            
            // Check if current month payment already exists
            $check_sql = "SELECT id FROM rent_payments 
                         WHERE user_id = {$row['user_id']} 
                         AND month_year = '$current_month'";
            $check_result = $conn->query($check_sql);
            
            if ($check_result->num_rows > 0) {
                // Update existing record with carryover
                $existing = $check_result->fetch_assoc();
                $update_sql = "UPDATE rent_payments 
                              SET previous_month_balance = $remaining_balance,
                                  late_fee = late_fee + $new_late_fee,
                                  is_carried_over = 1,
                                  carry_over_date = CURDATE()
                              WHERE id = {$existing['id']}";
                
                if ($conn->query($update_sql)) {
                    $rollover_count++;
                    
                    // Log the penalty
                    $penalty_sql = "INSERT INTO penalty_logs 
                        (payment_id, user_id, penalty_amount, penalty_type, reason, applied_date) 
                        VALUES ({$existing['id']}, {$row['user_id']}, $new_late_fee, 'late_fee', 
                        'Carryover late fee from $previous_month - Previous balance: ₹$remaining_balance', CURDATE())";
                    $conn->query($penalty_sql);
                } else {
                    $response['errors'][] = "Update error for user {$row['user_id']}: " . $conn->error;
                }
            } else {
                // Create new payment record for current month with carryover
                $new_payment_sql = "INSERT INTO rent_payments 
                    (user_id, room_id, month_year, amount, paid_amount, status, due_date, 
                     late_fee, previous_month_balance, is_carried_over, carry_over_date) 
                    VALUES (
                        {$row['user_id']}, 
                        {$row['room_id']}, 
                        '$current_month', 
                        {$row['rent']}, 
                        0, 
                        'Pending', 
                        DATE_ADD(CURDATE(), INTERVAL 5 DAY),
                        $new_late_fee,
                        $remaining_balance,
                        1,
                        CURDATE()
                    )";
                
                if ($conn->query($new_payment_sql)) {
                    $rollover_count++;
                    $new_payment_id = $conn->insert_id;
                    
                    // Log the penalty
                    $penalty_sql = "INSERT INTO penalty_logs 
                        (payment_id, user_id, penalty_amount, penalty_type, reason, applied_date) 
                        VALUES ($new_payment_id, {$row['user_id']}, $new_late_fee, 'late_fee', 
                        'Carryover late fee from $previous_month - Previous balance: ₹$remaining_balance', CURDATE())";
                    $conn->query($penalty_sql);
                } else {
                    $response['errors'][] = "Insert error for user {$row['user_id']}: " . $conn->error;
                }
            }
        }
    }
    
    // Create new payment records for active users who don't have current month record
    $new_users_sql = "INSERT INTO rent_payments (user_id, room_id, month_year, amount, paid_amount, status, due_date, late_fee, previous_month_balance, is_carried_over)
        SELECT u.id, u.room_id, '$current_month', r.rent, 0, 'Pending', 
               DATE_ADD(CURDATE(), INTERVAL 5 DAY), 0, 0, 0
        FROM users u 
        JOIN rooms r ON u.room_id = r.room_id 
        WHERE u.role = 'User' 
        AND u.status = 'active'
        AND NOT EXISTS (
            SELECT 1 FROM rent_payments rp 
            WHERE rp.user_id = u.id AND rp.month_year = '$current_month'
        )";
    
    $new_users_result = $conn->query($new_users_sql);
    
    if ($new_users_result) {
        $response['new_users_count'] = $conn->affected_rows;
    } else {
        $response['errors'][] = "New users creation error: " . $conn->error;
    }
    
    $response['rollover_count'] = $rollover_count;
    
    return $response;
}

// Manual trigger for testing
if (isset($_GET['action']) && $_GET['action'] == 'rollover') {
    $result = monthlyRentRollover($conn);
    echo json_encode($result);
    exit;
}

// Auto-execute on 1st of every month (for cron job)
if (date('d') == '01' || (isset($_GET['auto']) && $_GET['auto'] == 'true')) {
    $result = monthlyRentRollover($conn);
    
    // Log the auto-execution
    $log_sql = "INSERT INTO system_logs (action, details, created_at) 
                VALUES ('monthly_rollover', 'Auto-executed for month: " . date('Y-m') . "', NOW())";
    $conn->query($log_sql);
    
    if (!isset($_GET['auto'])) {
        echo json_encode($result);
    }
    exit;
}

echo json_encode([
    "success" => true, 
    "message" => "Monthly rollover system ready",
    "current_date" => date('Y-m-d'),
    "auto_execute_day" => (date('d') == '01') ? "Today is rollover day!" : "Rollover runs on 1st of month"
]);
?>