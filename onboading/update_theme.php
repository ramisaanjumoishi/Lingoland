<?php
header("Content-Type: application/json");
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["success"=>false, "message"=>"Not logged in"]);
    exit();
}

$user_id = $_SESSION['user_id'];

$conn = new mysqli("localhost", "root", "", "lingoland_db");
if ($conn->connect_error) {
    echo json_encode(["success"=>false, "message"=>"DB error"]);
    exit();
}

$theme_id = intval($_POST['theme_id'] ?? 1);

// Insert theme (no duplicate primary key because sets table uses auto-increment set_id)
$sql = "INSERT INTO sets (user_id, theme_id, set_on) VALUES (?, ?, NOW())";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $user_id, $theme_id);

if ($stmt->execute()) {
    echo json_encode(["success" => true]);
} else {
    echo json_encode(["success"=>false, "message"=>$stmt->error]);
}
?>
