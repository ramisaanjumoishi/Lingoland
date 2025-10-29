<?php
session_start();

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "lingoland_db";

$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Only process POST requests
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Debugging (uncomment to test):
    // echo '<pre>'; print_r($_POST); echo '</pre>'; exit;

    // Retrieve and sanitize inputs
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');


    if (empty($first_name) || empty($last_name) || empty($email) || empty($password) || empty($confirm_password)) {
        echo "<script>alert('Please fill in all fields.'); window.history.back();</script>";
        exit();
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo "<script>alert('Invalid email format.'); window.history.back();</script>";
        exit();
    }

    if ($password !== $confirm_password) {
        echo "<script>alert('Passwords do not match.'); window.history.back();</script>";
        exit();
    }

   
    $hashed_password = password_hash($password, PASSWORD_BCRYPT);

  
    $sql = "INSERT INTO user (first_name, last_name, email, password, created_at, verified_at, role_id)
            VALUES (?, ?, ?, ?, NOW(), NULL, 1)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssss", $first_name, $last_name, $email, $hashed_password);

    if ($stmt->execute()) {
        $_SESSION['user_id'] = $stmt->insert_id;
        $_SESSION['email'] = $email;
        $_SESSION['first_name'] = $first_name;

        // Redirect to 2FA registration or dashboard
        header("Location: ../register_2FA/register_2FA.php");
        exit();
    } else {
        if (strpos($conn->error, 'Duplicate') !== false) {
            echo "<script>alert('This email is already registered.'); window.history.back();</script>";
        } else {
            echo "Database Error: " . $conn->error;
        }
    }

    $stmt->close();
}

$conn->close();
?>
