<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json");   
require 'config.php';

// total capacity
$res = $conn->query("SELECT SUM(capacity) AS total_capacity FROM rooms");
$total_capacity = $res->fetch_assoc()["total_capacity"] ?? 0;

// total members
$res = $conn->query("SELECT COUNT(*) AS total_members FROM room_members");
$total_members = $res->fetch_assoc()["total_members"] ?? 0;

// available rooms
$res = $conn->query("SELECT COUNT(*) AS available_rooms FROM rooms r
  LEFT JOIN (
    SELECT room_id, COUNT(*) as cnt FROM room_members GROUP BY room_id
  ) m ON r.room_id = m.room_id
  WHERE IFNULL(m.cnt,0) < r.capacity");
$available_rooms = $res->fetch_assoc()["available_rooms"] ?? 0;

// today's meals
$res = $conn->query("SELECT COUNT(*) AS meals FROM menu WHERE date = CURDATE()");
$todays_meals = $res->fetch_assoc()["meals"] ?? 0;

// pending complaints
$res = $conn->query("SELECT COUNT(*) AS complaints FROM complaints WHERE status != 'Resolved'");
$pending_complaints = $res->fetch_assoc()["complaints"] ?? 0;

// occupancy %
$occupancy = $total_capacity > 0 ? round(($total_members / $total_capacity) * 100) : 0;

$res = $conn->query("SELECT COUNT(*) AS feedback FROM feedback");
$feedback = $res->fetch_assoc()["feedback"] ?? 0;

// ACTUAL TOTAL REVENUE (from rent_payments table - current month)
$currentMonth = date('Y-m');
$res = $conn->query("
    SELECT COALESCE(SUM(paid_amount), 0) AS total_revenue 
    FROM rent_payments 
    WHERE month_year = '$currentMonth' 
    AND status IN ('Paid', 'Partial')
");
$row = $res->fetch_assoc();
$total_revenue = $row ? (float)$row["total_revenue"] : 0;

// Optional: If you want to show total potential revenue as well
$res = $conn->query("
    SELECT COALESCE(SUM(r.rent * m.member_count), 0) AS potential_revenue
    FROM (
        SELECT room_id, COUNT(*) AS member_count 
        FROM room_members 
        GROUP BY room_id
    ) AS m
    INNER JOIN rooms r ON r.room_id = m.room_id
");
$row = $res->fetch_assoc();
$potential_revenue = $row ? (float)$row["potential_revenue"] : 0;

// NEW: Pending contact messages (messages without admin responses)
$res = $conn->query("
    SELECT COUNT(*) AS pending_contacts 
    FROM contacts c 
    WHERE NOT EXISTS (
        SELECT 1 FROM contact_responses cr WHERE cr.contact_id = c.id
    )
");
$pending_contacts = $res->fetch_assoc()["pending_contacts"] ?? 0;

// NEW: Total contact messages (all time)
$res = $conn->query("SELECT COUNT(*) AS total_contacts FROM contacts");
$total_contacts = $res->fetch_assoc()["total_contacts"] ?? 0;

// NEW: Today's contact messages
$res = $conn->query("SELECT COUNT(*) AS todays_contacts FROM contacts WHERE DATE(created_at) = CURDATE()");
$todays_contacts = $res->fetch_assoc()["todays_contacts"] ?? 0;

// NEW: This week's contact messages
$res = $conn->query("SELECT COUNT(*) AS weekly_contacts FROM contacts WHERE YEARWEEK(created_at) = YEARWEEK(CURDATE())");
$weekly_contacts = $res->fetch_assoc()["weekly_contacts"] ?? 0;

echo json_encode([
  "occupancy" => $occupancy,
  "available_rooms" => $available_rooms,
  "todays_meals" => $todays_meals,
  "pending_complaints" => $pending_complaints,
  "feedback" => $feedback,
  "total_revenue" => $total_revenue,
  "potential_revenue" => $potential_revenue, // Optional: for reference
  "pending_contacts" => $pending_contacts, // New: Messages needing response
  "total_contacts" => $total_contacts, // New: All contact messages
  "todays_contacts" => $todays_contacts, // New: Today's messages
  "weekly_contacts" => $weekly_contacts, // New: This week's messages
]);
?>