<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

$host = "localhost";
$user = "root";
$pass = "";
$db = "lingoland_db";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if (!isset($_SESSION['user_id'])) {
    die("Session missing user_id. Please log in again.");
}

$user_id = $_SESSION['user_id'];
$proficiency_self = trim($_POST['proficiency_self'] ?? '');
$learning_style = trim($_POST['learning_style'] ?? '');
$personality_type = trim($_POST['personality_type'] ?? '');

if (empty($proficiency_self) || empty($learning_style) || empty($personality_type)) {
    die("Missing required fields.");
}

// Check if user profile exists
$check = $conn->prepare("SELECT profile_id FROM user_profile WHERE user_id = ?");
$check->bind_param("i", $user_id);
$check->execute();
$result = $check->get_result();

if ($result->num_rows > 0) {
    // Update record
    $update = $conn->prepare("
        UPDATE user_profile 
        SET proficiency_self = ?, learning_style = ?, personality_type = ?
        WHERE user_id = ?
    ");
    $update->bind_param("sssi", $proficiency_self, $learning_style, $personality_type, $user_id);
    if ($update->execute()) {
        echo "success";
    } else {
        echo "Update failed: " . $conn->error;
    }
    $update->close();
} else {
    // Failsafe insert (if profile somehow missing)
    $insert = $conn->prepare("
        INSERT INTO user_profile (user_id, proficiency_self, learning_style, personality_type)
        VALUES (?, ?, ?, ?)
    ");
    $insert->bind_param("isss", $user_id, $proficiency_self, $learning_style, $personality_type);
    if ($insert->execute()) {
        echo "success";
    } else {
        echo "Insert failed: " . $conn->error;
    }
    $insert->close();
}

$check->close();
$conn->close();
?>
