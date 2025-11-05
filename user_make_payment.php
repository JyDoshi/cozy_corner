<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

require 'config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $user_id = $input['user_id'] ?? 0;
    $payment_id = $input['payment_id'] ?? 0;
    $amount = $input['amount'] ?? 0;
    $month_year = $input['month_year'] ?? '';
    
    if ($user_id == 0 || $payment_id == 0 || $amount <= 0) {
        echo json_encode(["success" => false, "message" => "Invalid payment data"]);
        exit;
    }
    
    // Get current payment details
    $sql = "SELECT * FROM rent_payments WHERE id = $payment_id AND user_id = $user_id";
    $result = $conn->query($sql);
    
    if ($result->num_rows == 0) {
        echo json_encode(["success" => false, "message" => "Payment record not found"]);
        exit;
    }
    
    $payment = $result->fetch_assoc();
    $current_paid = floatval($payment['paid_amount']);
    $total_amount = floatval($payment['amount']) + floatval($payment['late_fee']) + floatval($payment['previous_month_balance']);
    $new_paid = $current_paid + floatval($amount);
    
    // Determine new status
    $new_status = 'Pending';
    if ($new_paid >= $total_amount) {
        $new_status = 'Paid';
        $new_paid = $total_amount; // Prevent overpayment
    } elseif ($new_paid > 0) {
        $new_status = 'Partial';
    }
    
    // Update payment record
    $update_sql = "UPDATE rent_payments 
                  SET paid_amount = $new_paid, 
                      status = '$new_status', 
                      payment_date = CURDATE()
                  WHERE id = $payment_id";
    
    if ($conn->query($update_sql)) {
        // Log the payment transaction
        $transaction_sql = "INSERT INTO payment_transactions 
                           (payment_id, user_id, amount, transaction_date, payment_method) 
                           VALUES ($payment_id, $user_id, $amount, NOW(), 'Online')";
        $conn->query($transaction_sql);
        
        echo json_encode([
            "success" => true, 
            "message" => "Payment processed successfully",
            "new_status" => $new_status,
            "total_paid" => $new_paid
        ]);
    } else {
        echo json_encode(["success" => false, "message" => "Database error: " . $conn->error]);
    }
    exit;
}

echo json_encode(["success" => false, "message" => "Invalid request method"]);
?>