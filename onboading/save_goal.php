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
$learning_goal = trim($_POST['learning_goal'] ?? '');
$target_exam = trim($_POST['target_exam'] ?? '');

if (empty($learning_goal)) {
    die("No learning goal received.");
}

// ✅ Check if profile exists for this user
$check = $conn->prepare("SELECT profile_id FROM user_profile WHERE user_id = ?");
$check->bind_param("i", $user_id);
$check->execute();
$result = $check->get_result();

if ($result->num_rows > 0) {
    // Update existing record
    $update = $conn->prepare("
        UPDATE user_profile 
        SET learning_goal = ?, target_exam = ?
        WHERE user_id = ?
    ");
    $update->bind_param("ssi", $learning_goal, $target_exam, $user_id);
    if ($update->execute()) {
        echo "success";
    } else {
        echo "Update failed: " . $conn->error;
    }
    $update->close();
} else {
    // Create new profile (fallback)
    $insert = $conn->prepare("
        INSERT INTO user_profile (user_id, learning_goal, target_exam)
        VALUES (?, ?, ?)
    ");
    $insert->bind_param("iss", $user_id, $learning_goal, $target_exam);
    if ($insert->execute()) {
        echo "success";
    } else {
        echo "Insert failed: " . $conn->error;
    }
    $insert->close();
}

$conn->close();
?>
