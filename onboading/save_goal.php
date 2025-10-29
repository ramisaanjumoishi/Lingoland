<?php

session_start();
var_export($_SESSION);
error_reporting(E_ALL);
ini_set('display_errors', 1);

$host = "localhost";
$user = "root";
$pass = "";
$db   = "lingoland_db";

// --- DB connect
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    http_response_code(500);
    die("Connection failed: " . $conn->connect_error);
}

// --- check session user
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    http_response_code(401);
    die("Session missing user_id. Please log in.");
}
$user_id = (int) $_SESSION['user_id'];

// --- POST inputs (sanitized a little)
$learning_goal = isset($_POST['learning_goal']) ? trim($_POST['learning_goal']) : '';
$target_exam   = isset($_POST['target_exam']) ? trim($_POST['target_exam']) : '';

if ($learning_goal === '') {
    http_response_code(400);
    die("No learning goal received.");
}

// --- check if profile exists using store_result (no get_result)
$check = $conn->prepare("SELECT profile_id FROM user_profile WHERE user_id = ?");
if (!$check) {
    http_response_code(500);
    die("Prepare failed (check): " . $conn->error);
}
$check->bind_param("i", $user_id);
if (!$check->execute()) {
    http_response_code(500);
    die("Execute failed (check): " . $check->error);
}
$check->store_result();
$exists = ($check->num_rows > 0);
$check->free_result();
$check->close();

if ($exists) {
    // update
    $update = $conn->prepare("UPDATE user_profile SET learning_goal = ?, target_exam = ? WHERE user_id = ?");
    if (!$update) {
        http_response_code(500);
        die("Prepare failed (update): " . $conn->error);
    }
    $update->bind_param("ssi", $learning_goal, $target_exam, $user_id);
    if ($update->execute()) {
        echo "success";
    } else {
        http_response_code(500);
        echo "Update failed: " . $update->error;
    }
    $update->close();
} else {
    // insert
    $insert = $conn->prepare("INSERT INTO user_profile (user_id, learning_goal, target_exam) VALUES (?, ?, ?)");
    if (!$insert) {
        http_response_code(500);
        die("Prepare failed (insert): " . $conn->error);
    }
    $insert->bind_param("iss", $user_id, $learning_goal, $target_exam);
    if ($insert->execute()) {
        echo "success";
    } else {
        http_response_code(500);
        echo "Insert failed: " . $insert->error;
    }
    $insert->close();
}

$conn->close();
?>
