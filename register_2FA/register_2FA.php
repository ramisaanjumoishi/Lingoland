<?php
session_start();
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;


require 'vendor/autoload.php';


$servername = "localhost";
$username = "root";
$password = "";
$dbname = "lingoland_db";  

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['email'])) {
    header("Location: ../login_signup/register.php");
    exit();
}
$user_id = $_SESSION['user_id']; 
$submitted_email = $_SESSION['email']; 


if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $otp = $_POST['otp'];
   

    if ($otp == $_SESSION['otp']) {
      
        $sql = "SELECT email FROM user WHERE user_id = '$user_id'";
        $result = $conn->query($sql);
        $user = $result->fetch_assoc();
        $stored_email = $user['email'];

        if ($submitted_email == $stored_email) {
           
            $sql = "UPDATE user SET verified_at = NOW() WHERE user_id = '$user_id'";
            if ($conn->query($sql) === TRUE) {
                header("Location: ../login_signup/login_signup.html");  
                exit();
            } else {
                echo "Error updating record: " . $conn->error;
            }
        } else {
            echo "The email does not match. Please try again.";
        }
    } else {
        echo "Invalid OTP. Please try again.";
    }
} else {
    
    $_SESSION['otp'] = rand(100000, 999999);


    $sql = "SELECT email FROM user WHERE user_id = '$user_id'";
    $result = $conn->query($sql);
    $user = $result->fetch_assoc();
    $user_email = $user['email'];


    $mail = new PHPMailer(true);
    try {
      
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';  
        $mail->SMTPAuth = true;
        $mail->Username = 'lingolandbd@gmail.com'; 
        $mail->Password = 'scfizxylszqpfynk'; 
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        
        $mail->setFrom('ramisa.anjum345@gmail.com', 'LingoLand');
        $mail->addAddress($user_email); 

        
        $mail->isHTML(true);
        $mail->Subject = 'Your 2FA Code';
        $mail->Body    = "Your One-Time Password (OTP) is: <strong>" . $_SESSION['otp'] . "</strong>";

        
        $mail->send();
        /*echo 'OTP sent to your email!';*/
    } catch (Exception $e) {
        echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
    }
}


$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Lingoland | Email Verification</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="register_2FA.css">
</head>

<body>
  <div class="verify-container">
    <h2>Two-Factor Authentication</h2>
    <p>We’ve sent a 6-digit verification code to your email.<br>
    Enter it below to verify and complete your registration.</p>

    <form action="register_2FA.php" method="POST">
      <div class="input-box">
        <input type="text" name="otp" placeholder="Enter your OTP" required maxlength="6">
        <i class="fa-solid fa-shield-halved"></i>
      </div>

      <button type="submit" name="verify" class="verify-btn">Verify OTP</button>
    </form>

    <a href="register.php" class="resend-link">Didn’t get the OTP? Resend</a>
  </div>

  <script>
    // Optional: fade-in animation delay
    document.addEventListener("DOMContentLoaded", () => {
      document.querySelector(".verify-container").style.opacity = "1";
    });
  </script>
</body>
</html>


