<?php
session_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Log the received data
error_log("=== update_theme.php called ===");
error_log("SESSION user_id: " . ($_SESSION['user_id'] ?? 'NOT SET'));
error_log("POST data: " . print_r($_POST, true));
error_log("RAW POST: " . file_get_contents('php://input'));

if (!isset($_SESSION['user_id'])) {
    error_log("ERROR: User not authenticated");
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$user_id = $_SESSION['user_id'];
$theme_id = $_POST['theme_id'] ?? null;

error_log("Processing - User ID from session: $user_id, Theme ID from POST: " . ($theme_id ?? 'NULL'));

// Validate theme_id
if ($theme_id === null) {
    error_log("ERROR: theme_id not received in POST");
    echo json_encode(['success' => false, 'message' => 'Theme ID not provided']);
    exit();
}

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "lingoland_db";

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    error_log("ERROR: DB connection failed: " . mysqli_connect_error());
    echo json_encode(['success' => false, 'message' => 'DB connection failed']);
    exit();
}

// First, check if the user already has a theme setting
$check_sql = "SELECT id FROM sets WHERE user_id = ?";
$check_stmt = $conn->prepare($check_sql);
if (!$check_stmt) {
    error_log("ERROR: Prepare failed: " . $conn->error);
    echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
    exit();
}

$check_stmt->bind_param("i", $user_id);
$check_stmt->execute();
$check_result = $check_stmt->get_result();

error_log("Check result rows: " . $check_result->num_rows);

if ($check_result->num_rows > 0) {
    // Update existing theme setting
    $sql = "UPDATE sets SET theme_id = ?, set_on = NOW() WHERE user_id = ?";
    error_log("Updating existing theme setting");
} else {
    // Insert new theme setting
    $sql = "INSERT INTO sets (user_id, theme_id, set_on) VALUES (?, ?, NOW())";
    error_log("Inserting new theme setting");
}

$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("ERROR: Prepare failed: " . $conn->error);
    echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
    exit();
}

$stmt->bind_param("ii", $theme_id, $user_id);

if ($stmt->execute()) {
    error_log("SUCCESS: Theme updated in database");
    echo json_encode(['success' => true, 'message' => 'Theme updated successfully']);
} else {
    error_log("ERROR: Theme update failed: " . $stmt->error);
    echo json_encode(['success' => false, 'message' => 'Theme update failed: ' . $stmt->error]);
}

$check_stmt->close();
$stmt->close();
$conn->close();
?>