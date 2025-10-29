<?php
session_start();

$host = "localhost";
$user = "root";
$pass = "";
$db = "lingoland_db";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}


// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    die("User not logged in.");
}

$user_id = $_SESSION['user_id'];
$age_group = $_POST['age_group'] ?? '';
$native_language = $_POST['native_language'] ?? '';

if ($age_group && $native_language) {
    // Check if this user already has a profile
    $check = $conn->prepare("SELECT profile_id FROM user_profile WHERE user_id = ?");
    $check->bind_param("i", $user_id);
    $check->execute();
    $result = $check->get_result();

    if ($result->num_rows > 0) {
        // Profile exists → Update instead
        $update = $conn->prepare("UPDATE user_profile SET age_group = ?, native_language = ? WHERE user_id = ?");
        $update->bind_param("ssi", $age_group, $native_language, $user_id);
        if ($update->execute()) {
            echo "Profile updated successfully!";
        } else {
            echo "Error updating profile: " . $conn->error;
        }
        $update->close();
    } else {
        // New user → Insert new profile
        $insert = $conn->prepare("INSERT INTO user_profile (user_id, age_group, native_language) VALUES (?, ?, ?)");
        $insert->bind_param("iss", $user_id, $age_group, $native_language);
        if ($insert->execute()) {
            echo "Profile created successfully!";
        } else {
            echo "Error creating profile: " . $conn->error;
        }
        $insert->close();
    }
    $check->close();
} else {
    echo "Missing required fields.";
}

$conn->close();
?>
