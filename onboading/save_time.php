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
if (!isset($_SESSION['user_id'])) {
    die("User not logged in");
}

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $daily_time = trim($_POST['daily_time']);

    // Extract numbers like 20 and 30 from "20-30 min/day"
    preg_match_all('/\d+/', $daily_time, $matches);
    $nums = $matches[0];

    if (count($nums) == 2) {
        $avg = ($nums[0] + $nums[1]) / 2; // take average
    } elseif (count($nums) == 1) {
        $avg = $nums[0];
    } else {
        $avg = 20; // default fallback
    }

    // Convert to weekly minutes
    $weekly_time_minutes = $avg * 7;

    // Update user_profile
    $update_sql = "UPDATE user_profile 
                   SET weekly_time_minutes = ? 
                   WHERE user_id = ?";

    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param("ii", $weekly_time_minutes, $user_id);
    if ($stmt->execute()) {
        // Redirect to dashboard
        header("Location: ../user_dashboard/user-dashboard.php");
        exit();
    } else {
        echo "Error updating record: " . $conn->error;
    }

    $stmt->close();
    $conn->close();
} else {
    echo "Invalid request";
}
?>
