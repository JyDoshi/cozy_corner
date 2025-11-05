<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Content-Type: application/json");

require 'config.php';

$filter = $_GET['filter'] ?? 'All';
$selectedMonth = $_GET['month'] ?? date('Y-m');

// Validate and sanitize the month parameter
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}

// Query revenue breakdown with actual rent payments data
$sql = "
    SELECT 
        r.room_id, 
        r.rent,
        r.type,
        r.capacity,
        COUNT(DISTINCT m.member_id) AS occupied,
        -- Get collected amount per room (not multiplied by members)
        COALESCE(
            (SELECT SUM(paid_amount) 
             FROM rent_payments 
             WHERE room_id = r.room_id 
             AND month_year = '$selectedMonth'
             AND status IN ('Paid', 'Partial')
            ), 0
        ) AS total_collected,
        -- Get expected amount per room (not multiplied by members)
        COALESCE(
            (SELECT SUM(amount) 
             FROM rent_payments 
             WHERE room_id = r.room_id 
             AND month_year = '$selectedMonth'
            ), 0
        ) AS total_expected,
        -- Potential revenue based on occupancy
        (r.rent * COUNT(DISTINCT m.member_id)) AS potential_revenue,
        -- Collection rate
        CASE 
            WHEN COALESCE(
                (SELECT SUM(amount) 
                 FROM rent_payments 
                 WHERE room_id = r.room_id 
                 AND month_year = '$selectedMonth'
                ), 0
            ) > 0 
            THEN (
                COALESCE(
                    (SELECT SUM(paid_amount) 
                     FROM rent_payments 
                     WHERE room_id = r.room_id 
                     AND month_year = '$selectedMonth'
                     AND status IN ('Paid', 'Partial')
                    ), 0
                ) / 
                COALESCE(
                    (SELECT SUM(amount) 
                     FROM rent_payments 
                     WHERE room_id = r.room_id 
                     AND month_year = '$selectedMonth'
                    ), 1
                ) * 100
            )
            ELSE 0 
        END AS collection_rate
    FROM rooms r
    LEFT JOIN room_members m ON r.room_id = m.room_id
    GROUP BY r.room_id, r.rent, r.type, r.capacity
    ORDER BY r.room_id
";

$res = $conn->query($sql);
$data = [];
while ($row = $res->fetch_assoc()) {
    $row['occupied'] = (int)($row['occupied'] ?? 0);
    $row['rent'] = (float)($row['rent'] ?? 0);
    $row['total_collected'] = (float)($row['total_collected'] ?? 0);
    $row['total_expected'] = (float)($row['total_expected'] ?? 0);
    $row['potential_revenue'] = (float)($row['potential_revenue'] ?? 0);
    $row['collection_rate'] = (float)($row['collection_rate'] ?? 0);
    $data[] = $row;
}

echo json_encode($data);
?>