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

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    die("Error: user not logged in");
}
$user_id = $_SESSION['user_id'];

// Get JSON array of selected interests
if (!isset($_POST['interests_json']) || empty($_POST['interests_json'])) {
    die("Error: no interests provided");
}

$interests = json_decode($_POST['interests_json'], true);
if (!is_array($interests) || count($interests) == 0) {
    die("Error: invalid data");
}

try {
    // 1️⃣ Get profile_id for the current user
    $profileQuery = $conn->prepare("SELECT profile_id FROM user_profile WHERE user_id = ?");
    $profileQuery->bind_param("i", $user_id);
    $profileQuery->execute();
    $result = $profileQuery->get_result();

    if ($result->num_rows === 0) {
        die("Error: no profile found for this user");
    }

    $profileRow = $result->fetch_assoc();
    $profile_id = $profileRow['profile_id'];

    // 2️⃣ Loop through each interest name, find its ID, and insert mapping
    $insertStmt = $conn->prepare("INSERT INTO user_interest (profile_id, interest_id, added_on)
                                  VALUES (?, ?, NOW())");

    $interestLookup = $conn->prepare("SELECT interest_id FROM interest WHERE interest_name = ?");

    foreach ($interests as $interestName) {
        // find interest_id
        $interestLookup->bind_param("s", $interestName);
        $interestLookup->execute();
        $res = $interestLookup->get_result();

        if ($res->num_rows > 0) {
            $row = $res->fetch_assoc();
            $interest_id = $row['interest_id'];

            // insert mapping
            $insertStmt->bind_param("ii", $profile_id, $interest_id);
            $insertStmt->execute();
        }
    }

    echo "success";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
