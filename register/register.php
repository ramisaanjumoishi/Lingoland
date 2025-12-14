<?php


$servername = "localhost";
$username = "root";
$password = "";
$dbname = "lingoland_db";  

$conn = new mysqli($servername, $username, $password, $dbname);


if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $first_name = mysqli_real_escape_string($conn, $_POST['first_name']);
    $last_name = mysqli_real_escape_string($conn, $_POST['last_name']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $password = mysqli_real_escape_string($conn, $_POST['password']);
    $confirm_password = mysqli_real_escape_string($conn, $_POST['confirm_password']);
    
  
    if ($password != $confirm_password) {
        echo "Passwords do not match. Please try again.";
        exit();
    }

    

   
    $sql = "INSERT INTO user (first_name, last_name, email, password, created_at, verified_at, role_id)
            VALUES ('$first_name', '$last_name', '$email', '$password', NOW(), NULL, 1)";  

    if ($conn->query($sql) === TRUE) {
       
        session_start(); 
        $_SESSION['user_id'] = $conn->insert_id; 
        $_SESSION['email'] = $email;  // Store the email in the session
       
        header("Location: ../register_2FA/register_2FA.php");
        exit();  
    } else {
        echo "Error: " . $sql . "<br>" . $conn->error;
    }
}

$conn->close();
?>
