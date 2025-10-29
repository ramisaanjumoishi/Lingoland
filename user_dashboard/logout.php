<?php

session_start();  

if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id']; 
    
    log_user_activity($user_id, 'logout');

  
    session_destroy();
} else {
 
    header("Location: ../login/login_process.php");
    exit();
}



header("Location: ../home/home.html");
exit();

function log_user_activity($user_id, $action_type) {
 
    $servername = "localhost";
    $username = "root";
    $password = "";
    $dbname = "lingoland_db";

    $conn = mysqli_connect($servername, $username, $password, $dbname);

    if (!$conn) {
        die("Connection failed: " . mysqli_connect_error());
    }

    $action_time = date("Y-m-d H:i:s");

    $log_sql = "INSERT INTO activity_log (user_id, action_type, action_time) 
                VALUES (?, ?, ?)";

    $stmt = $conn->prepare($log_sql);
    $stmt->bind_param("iss", $user_id, $action_type, $action_time);
    $stmt->execute();
    $stmt->close();

    
    mysqli_close($conn);
}
?>
