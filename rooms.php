<?php
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Headers: Content-Type");
    header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
    header("Content-Type: application/json");
    require 'config.php';

    $action = $_GET['action'] ?? 'list';

    /* ==================== LIST ROOMS ==================== */
    if ($action == "list") {
        $sql = "SELECT r.*, 
                    COUNT(m.member_id) as occupied
                FROM rooms r
                LEFT JOIN room_members m ON r.room_id = m.room_id
                GROUP BY r.room_id 
                ORDER BY r.floor";
        $result = $conn->query($sql);

        $rooms = [];
        while ($row = $result->fetch_assoc()) {
            $capacity = (int)$row["capacity"];
            $occupied = (int)$row["occupied"];

            // Auto-set status
            if ($occupied == 0) {
                $row["status"] = "Available";
            } elseif ($occupied < $capacity) {
                $row["status"] = "Partial";
            } else {
                $row["status"] = "Full";
            }

            $rooms[] = $row;
        }
        echo json_encode($rooms);
        exit;
    }

    /* ==================== ADD ROOM ==================== */
    if ($action == 'add') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Add validation for required fields
        if (!isset($data['type']) || !isset($data['floor']) || !isset($data['capacity']) || !isset($data['rent'])) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            exit;
        }
        
        $type = $conn->real_escape_string($data['type'] ?? '');
        $floor = (int)($data['floor'] ?? 1);
        $capacity = (int)($data['capacity'] ?? 1);
        $rent = (int)($data['rent'] ?? 0);

        // Validate type
        if (!in_array($type, ['AC', 'Non-AC'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid room type']);
            exit;
        }

        // Validate numbers
        if ($floor <= 0 || $capacity <= 0 || $rent < 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid numeric values']);
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO rooms (type, floor, capacity, rent) VALUES (?,?,?,?)");
        $stmt->bind_param('siii', $type, $floor, $capacity, $rent);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'id' => $stmt->insert_id]);
        } else {
            echo json_encode(['success' => false, 'message' => $conn->error]);
        }
        $stmt->close();
        exit;
    }

    /* ==================== DELETE ROOM ==================== */
    if ($action == 'delete') {
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM rooms WHERE room_id = ?");
        $stmt->bind_param('i', $id);
        echo json_encode(['success'=>$stmt->execute()]);
        exit;
    }

    /* ==================== UPDATE ROOM ==================== */
    if ($action == "update") {
        $id       = $_GET['id'] ?? '';
        $type     = $_GET['type'] ?? '';
        $floor    = $_GET['floor'] ?? '';
        $capacity = $_GET['capacity'] ?? '';
        $rent     = $_GET['rent'] ?? '';

        if (!$id || !$type || !$floor || !$capacity || $rent === '') {
            echo json_encode(["success" => false, "message" => "Missing fields"]);
            exit;
        }

        $id       = (int)$id;
        $type     = $conn->real_escape_string($type);
        $floor    = (int)$floor;
        $capacity = (int)$capacity;
        $rent     = (int)$rent;

        $sql = "UPDATE rooms 
                SET type='$type', floor='$floor', capacity='$capacity', rent='$rent' 
                WHERE room_id='$id'";
        
        if ($conn->query($sql) === TRUE) {
            echo json_encode(["success" => true, "message" => "Room updated successfully"]);
        } else {
            echo json_encode(["success" => false, "message" => $conn->error]);
        }
        exit;
    }
?>
