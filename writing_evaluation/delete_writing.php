<?php
session_start();
if (!isset($_SESSION['user_id'])) exit("ERR");

$uid = $_SESSION['user_id'];

if (!isset($_POST["id"])) {
    echo "NO_ID";
    exit;
}

$fid = intval($_POST["id"]);

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "lingoland_db";

$conn = new mysqli($servername, $username, $password, $dbname);
$conn->set_charset("utf8mb4");

$sql = "DELETE FROM writing_feedback WHERE feedback_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $fid);

if ($stmt->execute()) {
    echo "OK";
} else {
    echo "ERR";
}

$stmt->close();
$conn->close();
?>
