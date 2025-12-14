<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$user_id = $_SESSION['user_id'];
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "lingoland_db";

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'DB connection failed']);
    exit();
}

if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = '../settings/';
    
    // Create directory if it doesn't exist
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Generate unique filename
    $fileExtension = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
    $fileName = 'profile_' . $user_id . '_' . time() . '.' . $fileExtension;
    $filePath = $uploadDir . $fileName;
    
    // Validate and move uploaded file
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $fileType = $_FILES['profile_picture']['type'];
    
    if (in_array($fileType, $allowedTypes)) {
        if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $filePath)) {
            // Update database
            $sql = "UPDATE user SET profile_picture = ? WHERE user_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $fileName, $user_id);
            
            if ($stmt->execute()) {
                $_SESSION['profile_picture'] = $fileName;
                echo json_encode([
                    'success' => true, 
                    'message' => 'Profile picture updated',
                    'new_image_url' => '../settings/' . $fileName
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database update failed']);
            }
            $stmt->close();
        } else {
            echo json_encode(['success' => false, 'message' => 'File upload failed']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid file type']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'No file uploaded']);
}

$conn->close();
?>