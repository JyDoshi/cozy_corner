<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
require 'config.php';

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

$id = '';
$name = '';
$phone = '';
$imageFilename = '';
$existingImage = '';

// Set upload directory - relative to API folder
$uploadDir = __DIR__ . "/images/upload/";

// Create upload directory if it doesn't exist
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Check if we have multipart form data or JSON
if (!empty($_FILES['image']['name'])) {
    // Handle multipart form data (mobile)
    $id = $_POST['id'] ?? '';
    $name = $_POST['name'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $existingImage = $_POST['existing_image'] ?? '';
    
    // Process uploaded image only if new image is provided
    if ($_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $originalFileName = $_FILES["image"]["name"];
        $fileExtension = strtolower(pathinfo($originalFileName, PATHINFO_EXTENSION));
        
        // Check if file is JPG or PNG
        if ($fileExtension === 'jpg' || $fileExtension === 'jpeg' || $fileExtension === 'png') {
            
            // Check if it's the same as existing image by comparing file content
            $isSameImage = false;
            if (!empty($existingImage) && file_exists($uploadDir . $existingImage)) {
                $isSameImage = files_are_identical($_FILES["image"]["tmp_name"], $uploadDir . $existingImage);
            }
            
            if (!$isSameImage) {
                // Keep original filename
                $fileName = $originalFileName;
                $targetFilePath = $uploadDir . $fileName;
                
                // Move uploaded file
                if (move_uploaded_file($_FILES["image"]["tmp_name"], $targetFilePath)) {
                    $imageFilename = $fileName;
                    
                    // Delete old image if it exists and is different
                    if (!empty($existingImage) && $existingImage !== $imageFilename && file_exists($uploadDir . $existingImage)) {
                        unlink($uploadDir . $existingImage);
                    }
                }
            } else {
                // It's the same image, keep existing filename - DON'T STORE NEW FILE
                $imageFilename = $existingImage;
            }
        }
    }
} else {
    // Handle JSON data (web)
    $raw = file_get_contents("php://input");
    $json = json_decode($raw, true);
    
    if ($json) {
        $id = $json['id'] ?? '';
        $name = $json['name'] ?? '';
        $phone = $json['phone'] ?? '';
        $existingImage = $json['existing_image'] ?? '';
        
        // Process base64 image only if new image is provided
        if (isset($json['image_base64']) && !empty($json['image_base64'])) {
            $imgData = $json['image_base64'];
            $originalFileName = $json['image_name'] ?? 'profile.jpg';
            $fileExtension = strtolower(pathinfo($originalFileName, PATHINFO_EXTENSION));
            
            if ($fileExtension === 'jpg' || $fileExtension === 'jpeg' || $fileExtension === 'png') {
                
                // Check if it's the same as existing image
                $isSameImage = false;
                if (!empty($existingImage) && file_exists($uploadDir . $existingImage)) {
                    $tempNewImage = $uploadDir . 'temp_new_image.' . $fileExtension;
                    file_put_contents($tempNewImage, base64_decode($imgData));
                    $isSameImage = files_are_identical($tempNewImage, $uploadDir . $existingImage);
                    unlink($tempNewImage); // Clean up temp file
                }
                
                if (!$isSameImage) {
                    // Keep original filename
                    $fileName = $originalFileName;
                    $targetFilePath = $uploadDir . $fileName;
                    
                    // Save base64 image
                    $imageData = base64_decode($imgData);
                    if (file_put_contents($targetFilePath, $imageData) !== false) {
                        $imageFilename = $fileName;
                        
                        // Delete old image if it exists and is different
                        if (!empty($existingImage) && $existingImage !== $imageFilename && file_exists($uploadDir . $existingImage)) {
                            unlink($uploadDir . $existingImage);
                        }
                    }
                } else {
                    // It's the same image, keep existing filename - DON'T STORE NEW FILE
                    $imageFilename = $existingImage;
                }
            }
        }
    }
}

// If no new image provided, keep existing image
if (empty($imageFilename) && !empty($existingImage)) {
    $imageFilename = $existingImage;
}

// Check if we have user ID
if (empty($id)) {
    echo json_encode(["success" => false, "message" => "User ID is required"]);
    exit;
}

// Check if we have data to update
if (empty($name) && empty($phone) && empty($imageFilename)) {
    echo json_encode(["success" => false, "message" => "No data to update"]);
    exit;
}

// Update database
try {
    if (!empty($imageFilename)) {
        $stmt = $conn->prepare("UPDATE users SET name = ?, contact = ?, image = ? WHERE id = ?");
        $stmt->bind_param("sssi", $name, $phone, $imageFilename, $id);
    } else {
        $stmt = $conn->prepare("UPDATE users SET name = ?, contact = ? WHERE id = ?");
        $stmt->bind_param("ssi", $name, $phone, $id);
    }
    
    $stmt->execute();
    
    // Also update room_members table
    if (!empty($imageFilename)) {
        $stmt2 = $conn->prepare("UPDATE room_members SET name = ?, contact = ?, photo_path = ? WHERE user_id = ?");
        $stmt2->bind_param("sssi", $name, $phone, $imageFilename, $id);
    } else {
        $stmt2 = $conn->prepare("UPDATE room_members SET name = ?, contact = ? WHERE user_id = ?");
        $stmt2->bind_param("ssi", $name, $phone, $id);
    }
    $stmt2->execute();
    
    // Return the image filename (could be existing or new)
    echo json_encode([
        "success" => true, 
        "message" => "Profile updated successfully", 
        "image_filename" => $imageFilename ?: $existingImage
    ]);
    
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}

// Function to compare two files by content
function files_are_identical($file1, $file2) {
    if (filesize($file1) !== filesize($file2)) {
        return false;
    }
    
    $handle1 = fopen($file1, 'rb');
    $handle2 = fopen($file2, 'rb');
    
    $result = true;
    while (!feof($handle1)) {
        if (fread($handle1, 8192) !== fread($handle2, 8192)) {
            $result = false;
            break;
        }
    }
    
    fclose($handle1);
    fclose($handle2);
    
    return $result;
}
?>