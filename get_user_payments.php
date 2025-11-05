<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

require 'config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $user_id = $input['user_id'] ?? 0;
    
    if ($user_id == 0) {
        echo json_encode(["success" => false, "message" => "Invalid user ID"]);
        exit;
    }
    
    // Get user's payment records with CORRECT calculations
    $sql = "
        SELECT 
            rp.*,
            r.type as room_type,
            r.rent,
            -- CORRECT CALCULATION: Base rent + late fee + previous balance
            (rp.amount + COALESCE(rp.late_fee, 0) + COALESCE(rp.previous_month_balance, 0)) as total_amount,
            -- CORRECT CALCULATION: Total amount minus paid amount
            (rp.amount + COALESCE(rp.late_fee, 0) + COALESCE(rp.previous_month_balance, 0) - rp.paid_amount) as total_due,
            -- FIXED: Handle negative days_overdue properly
            CASE 
                WHEN DATEDIFF(CURDATE(), rp.due_date) < 0 THEN 0  -- Future dates = 0 days overdue
                ELSE CAST(DATEDIFF(CURDATE(), rp.due_date) AS UNSIGNED)
            END as days_overdue,
            CASE 
                WHEN (rp.amount + COALESCE(rp.late_fee, 0) + COALESCE(rp.previous_month_balance, 0) - rp.paid_amount) <= 0 THEN 'paid'
                WHEN DATEDIFF(CURDATE(), rp.due_date) > 3 THEN 'overdue'
                WHEN DATEDIFF(CURDATE(), rp.due_date) > 0 THEN 'due_soon'
                ELSE 'pending'
            END as payment_status
        FROM rent_payments rp
        JOIN rooms r ON rp.room_id = r.room_id
        WHERE rp.user_id = $user_id
        ORDER BY rp.month_year DESC
    ";
    
    $result = $conn->query($sql);
    $payments = [];
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            // Ensure all numeric fields are properly typed
            $row['amount'] = floatval($row['amount']);
            $row['paid_amount'] = floatval($row['paid_amount']);
            $row['late_fee'] = floatval($row['late_fee']);
            $row['total_amount'] = floatval($row['total_amount']);
            $row['total_due'] = floatval($row['total_due']);
            $row['days_overdue'] = intval($row['days_overdue']);
            
            // DEBUG: Add calculation breakdown
            $row['calculation_breakdown'] = [
                'base_rent' => $row['amount'],
                'late_fee' => $row['late_fee'],
                'previous_balance' => $row['previous_month_balance'] ?? 0,
                'total_calculated' => $row['total_amount'],
                'paid_amount' => $row['paid_amount'],
                'remaining_calculated' => $row['total_due'],
                'due_date' => $row['due_date'],
                'current_date' => date('Y-m-d')
            ];
            
            $payments[] = $row;
        }
    }
    
    // Calculate summary
    $summary = [
        'overdue_count' => 0,
        'due_soon_count' => 0,
        'total_overdue_amount' => 0,
        'notification_count' => 0
    ];
    
    foreach ($payments as $payment) {
        if ($payment['payment_status'] == 'overdue') {
            $summary['overdue_count']++;
            $summary['total_overdue_amount'] += floatval($payment['total_due']);
            $summary['notification_count']++;
        } elseif ($payment['payment_status'] == 'due_soon') {
            $summary['due_soon_count']++;
            $summary['notification_count']++;
        }
    }
    
    echo json_encode([
        "success" => true,
        "payments" => $payments,
        "summary" => $summary,
        "debug_info" => [
            "calculation" => "Base Rent + Late Fee + Previous Balance - Paid Amount = Total Due",
            "days_overdue_fix" => "Negative values (future dates) now return 0 instead of overflow"
        ]
    ]);
    exit;
}

echo json_encode(["success" => false, "message" => "Invalid request method"]);
?>