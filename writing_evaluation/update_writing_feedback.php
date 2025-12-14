<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

// Get the JSON data
$json_data = file_get_contents('php://input');
$data = json_decode($json_data, true);

if (!$data || !isset($data['feedback_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
    exit();
}

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "lingoland_db";

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Convert suggestion_json to proper JSON string if it's already a string
$suggestion_json = $data['suggestion_json'];
if (is_array($suggestion_json)) {
    $suggestion_json = json_encode($suggestion_json);
}

// Prepare update query
$sql = "UPDATE `writing_feedback` SET 
        submission_text = ?,
        grammar_score = ?,
        coherence_score = ?,
        vocabulary_score = ?,
        overall_score = ?,
        ai_feedback_summary = ?,
        suggestion_json = ?,
        submitted_at = ?
        WHERE feedback_id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param(
    "siiissssi",
    $data['submission_text'],
    $data['grammar_score'],
    $data['coherence_score'],
    $data['vocabulary_score'],
    $data['overall_score'],
    $data['ai_feedback_summary'],
    $suggestion_json,
    $data['submitted_at'],
    $data['feedback_id']
);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Writing updated successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update writing: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
?>