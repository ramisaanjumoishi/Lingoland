<?php
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);
error_log("THEME UPDATE PHP REACHED");

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$user_id = $_SESSION['user_id'];
$theme_id = $_POST['theme_id'] ?? null;

if ($theme_id === null) {
    echo json_encode(['success' => false, 'message' => 'Theme ID missing']);
    exit();
}

// DB connect
$conn = new mysqli("localhost", "root", "", "lingoland_db");
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'DB connect failed']);
    exit();
}

/*** IMPORTANT: Check if user already has a row */
$check_sql = "SELECT * FROM sets WHERE user_id = ? LIMIT 1";
$check_stmt = $conn->prepare($check_sql);
$check_stmt->bind_param("i", $user_id);
$check_stmt->execute();
$check_result = $check_stmt->get_result();

if ($check_result->num_rows > 0) {
    // UPDATE
    $sql = "UPDATE sets SET theme_id = ?, set_on = NOW() WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $theme_id, $user_id);

} else {
    // INSERT
    $sql = "INSERT INTO sets (user_id, theme_id, set_on) VALUES (?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $user_id, $theme_id);
}

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Theme updated']);
} else {
    echo json_encode(['success' => false, 'message' => $stmt->error]);
}

$stmt->close();
$conn->close();

?>
