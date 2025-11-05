<?php
session_start();
header("Content-Type: application/json");

if (isset($_SESSION['user_id']) && $_SESSION['role'] === 'admin') {
    echo json_encode([
        'status' => 'active',
        'user' => [
            'id' => $_SESSION['user_id'],
            'name' => $_SESSION['name'],
            'email' => $_SESSION['email'],
            'role' => $_SESSION['role']
        ]
    ]);
} else {
    echo json_encode(['status' => 'inactive']);
}
?>