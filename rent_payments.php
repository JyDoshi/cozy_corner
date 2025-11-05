<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

require 'config.php';

$action = $_GET['action'] ?? 'fetch';

if ($action == 'fetch') {
    $sql = "
        SELECT 
            rp.*, 
            u.name, 
            u.contact, 
            u.email, 
            rp.room_id, 
            ro.type as room_type, 
            ro.rent,
            (ro.rent - rp.paid_amount) as remaining_rent,
            (ro.rent + COALESCE(rp.late_fee, 0) + COALESCE(rp.previous_month_balance, 0) - rp.paid_amount) as remaining_total,
            COALESCE(rp.late_fee, 0) as late_fee,
            COALESCE(rp.previous_month_balance, 0) as previous_month_balance,
            rp.is_carried_over
        FROM rent_payments rp
        JOIN users u ON rp.user_id = u.id
        JOIN rooms ro ON rp.room_id = ro.room_id
        ORDER BY rp.month_year DESC, u.name ASC
    ";
    
    $res = $conn->query($sql);
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        // Calculate total amount including late fee and previous balance
        $r['total_amount'] = floatval($r['amount']) + floatval($r['late_fee']) + floatval($r['previous_month_balance']);
        $r['remaining_total'] = floatval($r['total_amount']) - floatval($r['paid_amount']);
        $rows[] = $r;
    }
    echo json_encode($rows); 
    exit;
}

if ($action == 'update') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $id = $input['id'] ?? 0;
    $paid_amount = $input['paid_amount'] ?? 0;
    $status = $input['status'] ?? 'Pending';
    $late_fee = $input['late_fee'] ?? 0;
    $previous_balance = $input['previous_balance'] ?? 0;
    
    // Convert ID to integer
    $id = intval($id);
    
    if ($id == 0) {
        echo json_encode(["success" => false, "message" => "Invalid payment ID"]);
        exit;
    }
    
    // Convert to proper types
    $paid_amount = floatval($paid_amount);
    $status = $conn->real_escape_string($status);
    $late_fee = floatval($late_fee);
    $previous_balance = floatval($previous_balance);
    
    // Get current payment details
    $current_sql = "SELECT * FROM rent_payments WHERE id = $id";
    $current_result = $conn->query($current_sql);
    $current_payment = $current_result->fetch_assoc();
    
    $total_amount = floatval($current_payment['amount']) + floatval($current_payment['late_fee']) + floatval($current_payment['previous_month_balance']);
    
    // Set payment date only if payment is made
    $payment_date = $paid_amount > 0 ? "CURDATE()" : "NULL";
    
    // If payment is fully paid, update status
    if ($paid_amount >= $total_amount) {
        $status = 'Paid';
        $paid_amount = $total_amount; // Prevent overpayment
        
        // If this is a carried over payment and fully paid, reset carryover flags
        if ($current_payment['is_carried_over'] == 1) {
            $reset_sql = "UPDATE rent_payments 
                         SET is_carried_over = 0, previous_month_balance = 0 
                         WHERE id = $id";
            $conn->query($reset_sql);
        }
    } elseif ($paid_amount > 0) {
        $status = 'Partial';
    }
    
    $sql = "UPDATE rent_payments 
            SET paid_amount = $paid_amount, 
                status = '$status', 
                payment_date = $payment_date
            WHERE id = $id";
    
    if ($conn->query($sql)) {
        echo json_encode(["success" => true, "message" => "Payment updated successfully"]);
    } else {
        echo json_encode(["success" => false, "message" => "Database error: " . $conn->error]);
    }
    exit;
}

echo json_encode(["success" => false, "message" => "Invalid action"]);
?>