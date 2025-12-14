<?php
session_start();

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "lingoland_db";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST['email'] ?? '');
    $password_input = trim($_POST['password'] ?? '');

    if (empty($email) || empty($password_input)) {
        echo "<script>alert('Please enter both email and password.'); window.history.back();</script>";
        exit();
    }

    // Get user
    $sql = "SELECT user_id, first_name, email, password, role_id FROM user WHERE email = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $row = $result->fetch_assoc()) {

        if (password_verify($password_input, $row['password'])) {

            $user_id = $row['user_id'];

            // Load session
            $_SESSION['user_id'] = $row['user_id'];
            $_SESSION['email'] = $row['email'];
            $_SESSION['first_name'] = $row['first_name'];
            $_SESSION['role_id'] = $row['role_id'];

            // Check if user has any prior login
$sql = "SELECT 1 FROM activity_log WHERE user_id = ? AND action_type = 'login' LIMIT 1";
$stmt_check = $conn->prepare($sql);
$stmt_check->bind_param("i", $user_id);
$stmt_check->execute();
$has_login = $stmt_check->get_result()->num_rows > 0;

// Check if onboarding is completed
$sql2 = "SELECT 1 FROM activity_log WHERE user_id = ? AND action_type = 'onboarding_completed' LIMIT 1";
$stmt_check2 = $conn->prepare($sql2);
$stmt_check2->bind_param("i", $user_id);
$stmt_check2->execute();
$has_completed = $stmt_check2->get_result()->num_rows > 0;

// Log this login (important)
log_user_activity($user_id, 'login', $conn);

// REDIRECTION LOGIC
if (!$has_login || !$has_completed) {
    // New user OR user who skipped/abandoned onboarding → must complete onboarding
    header("Location: ../onboading/onboarding_step1.php");
    exit();
} else {
    // Already onboarded → go to dashboard
    header("Location: ../user_dashboard/user-dashboard.php");
    exit();
}


        } else {
            echo "<script>alert('Incorrect password.'); window.history.back();</script>";
        }
    } else {
        echo "<script>alert('User not found. Please register first.'); window.history.back();</script>";
    }

    $stmt->close();
}

$conn->close();


// Function to record login activity
function log_user_activity($user_id, $action_type, $conn) {
    $action_time = date("Y-m-d H:i:s");
    $sql = "INSERT INTO activity_log (user_id, action_type, action_time) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $user_id, $action_type, $action_time);
    $stmt->execute();
    $stmt->close();
}
?>
