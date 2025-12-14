<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

// Read JSON
$payload = json_decode(file_get_contents("php://input"), true);
if (!$payload) {
    echo json_encode(['success' => false, 'message' => 'Invalid payload']);
    exit;
}

$question_id    = intval($payload['question_id'] ?? 0);
$attempt_id     = intval($payload['attempt_id'] ?? 0); // may not be used but keep
$user_id        = intval($payload['user_id'] ?? 0);
$quiz_id        = intval($payload['quiz_id'] ?? 0);
$attempt_number = intval($payload['attempt_number'] ?? 0);
$selected_text  = trim($payload['selected_option'] ?? '');

// Basic validation
if (!$question_id || !$user_id || !$quiz_id || !$attempt_number) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit;
}

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "lingoland_db";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'DB connection error']);
    exit;
}

// Get correct_answer text from question table
$qsql = "SELECT correct_answer FROM question WHERE question_id = ?";
$qstmt = $conn->prepare($qsql);
if (!$qstmt) {
    echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
    exit;
}
$qstmt->bind_param("i", $question_id);
$qstmt->execute();
$qres = $qstmt->get_result();
$qrow = $qres->fetch_assoc();
$correct_text = $qrow['correct_answer'] ?? '';

// Compare (case-insensitive, trimmed)
$is_correct = 0;
if (mb_strtolower(trim($selected_text)) === mb_strtolower(trim($correct_text))) {
    $is_correct = 1;
}

// Use REPLACE to avoid duplicate PK issues (your PK: question_id, user_id, quiz_id, attempt_number)
$sql = "REPLACE INTO answer (question_id, user_id, quiz_id, attempt_number, is_correct) VALUES (?, ?, ?, ?, ?)";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Prepare failed 2: ' . $conn->error]);
    exit;
}
$stmt->bind_param("iiiii", $question_id, $user_id, $quiz_id, $attempt_number, $is_correct);
$ok = $stmt->execute();

if ($ok) {
    echo json_encode(['success' => true, 'is_correct' => $is_correct]);
} else {
    echo json_encode(['success' => false, 'message' => $stmt->error]);
}

$stmt->close();
$qstmt->close();
$conn->close();
?>
