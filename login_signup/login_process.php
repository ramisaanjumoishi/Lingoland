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

    $sql = "SELECT user_id, first_name, email, password, role_id FROM user WHERE email = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $row = $result->fetch_assoc()) {
        // Verify password hash
        if (password_verify($password_input, $row['password'])) {
            $_SESSION['user_id'] = $row['user_id'];
            $_SESSION['email'] = $row['email'];
            $_SESSION['first_name'] = $row['first_name'];
            $_SESSION['role_id'] = $row['role_id'];

            // Log user activity
            log_user_activity($row['user_id'], 'login', $conn);

            header("Location: ../onboading/onboarding_step1.html");
            exit();
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
