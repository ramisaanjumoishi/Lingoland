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

// Get the field and value from POST data
$field = $_POST['field'] ?? '';
$value = $_POST['value'] ?? '';

// Validate field
$allowed_fields = ['firstName', 'lastName', 'emailField', 'passwordField'];
if (!in_array($field, $allowed_fields)) {
    echo json_encode(['success' => false, 'message' => 'Invalid field']);
    exit();
}

// Map JavaScript field names to database column names
$field_map = [
    'firstName' => 'first_name',
    'lastName' => 'last_name', 
    'emailField' => 'email',
    'passwordField' => 'password'
];

$db_field = $field_map[$field];

// Prepare the update query
$sql = "UPDATE user SET $db_field = ? WHERE user_id = ?";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
    exit();
}

$stmt->bind_param("si", $value, $user_id);

if ($stmt->execute()) {
    // Update session data if first_name or last_name changed
    if ($field === 'firstName') {
        $_SESSION['first_name'] = $value;
    } elseif ($field === 'lastName') {
        $_SESSION['last_name'] = $value;
    } elseif ($field === 'emailField') {
        $_SESSION['email'] = $value;
    }
    
    echo json_encode(['success' => true, 'message' => 'Profile updated successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Update failed: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
?>