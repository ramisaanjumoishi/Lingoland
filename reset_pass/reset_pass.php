<?php
session_start();


$host = "localhost";
$username = "root";
$password = "";
$database = "lingoland_db";


$conn = new mysqli($host, $username, $password, $database);


if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if (isset($_POST['reset'])) {
    $email = $_POST['email'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

   
    if ($new_password !== $confirm_password) {
        echo "<script>alert('Passwords do not match!'); window.history.back();</script>";
        exit;
    }

    
    $check = $conn->prepare("SELECT * FROM user WHERE email = ?");
    $check->bind_param("s", $email);
    $check->execute();
    $result = $check->get_result();

    if ($result->num_rows > 0) {
       
        $update = $conn->prepare("UPDATE user SET password = ? WHERE email = ?");
        $update->bind_param("ss", $new_password, $email);

        if ($update->execute()) {
            
            header("Location: ../login/login.html?reset=success");
            exit;
        } else {
            echo "<script>alert('Error resetting password.'); window.history.back();</script>";
        }
    } else {
        echo "<script>alert('Email not found!'); window.history.back();</script>";
    }
}
$conn->close();
?>
