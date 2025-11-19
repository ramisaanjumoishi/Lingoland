<?php
if (!isset($_GET["id"])) {
    die("No ID.");
}

$fid = intval($_GET["id"]);

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "lingoland_db";

$conn = new mysqli($servername, $username, $password, $dbname);
$conn->set_charset("utf8mb4");

$sql = "SELECT submission_text FROM writing_feedback WHERE feedback_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $fid);
$stmt->execute();
$res = $stmt->get_result();

if (!$row = $res->fetch_assoc()) {
    die("Not found.");
}

$text = $row["submission_text"];

// Output DOC file
header("Content-Type: application/msword");
header("Content-Disposition: attachment; filename=writing_$fid.doc");
header("Content-Length: " . strlen($text));

echo $text;
exit;
?>
