<?php
session_start();

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: *");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Expose-Headers: Content-Length, X-JSON");

// Get the image filename from query parameter
$imageName = $_GET['image'] ?? '';

if (empty($imageName)) {
    http_response_code(400);
    echo "Image name is required";
    exit;
}

// Security: Only allow alphanumeric, dots, underscores, and hyphens
if (!preg_match('/^[a-zA-Z0-9._-]+$/', $imageName)) {
    http_response_code(400);
    echo "Invalid image name";
    exit;
}

// Define the base upload directory
$uploadDir = __DIR__ . '/images/upload/';
$imagePath = $uploadDir . $imageName;

// Check if file exists
if (!file_exists($imagePath)) {
    http_response_code(404);
    echo "Image not found: " . $imageName;
    exit;
}

// Check if it's actually an image
$imageInfo = @getimagesize($imagePath);
if (!$imageInfo) {
    http_response_code(400);
    echo "File is not a valid image";
    exit;
}

// Get MIME type
$mimeType = $imageInfo['mime'];

// Set proper headers for image
header("Content-Type: $mimeType");
header("Content-Length: " . filesize($imagePath));
header("Cache-Control: public, max-age=86400"); // Cache for 1 day

// Output the image
readfile($imagePath);
exit;
?>