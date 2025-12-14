<?php
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

    $sql = "INSERT INTO activity_log (user_id, action_type, action_time) 
            VALUES ('$user_id', '$action_type', '$action_time')";


    if (mysqli_query($conn, $sql)) {
        return true;
    } else {
        echo "Error: " . mysqli_error($conn);
        return false;
    }

   
    mysqli_close($conn);
}
?>
