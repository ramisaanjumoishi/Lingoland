<?php
// writing_eval_save.php - Pure API endpoint
header('Content-Type: application/json; charset=utf-8');
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Only accept POST JSON
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only POST allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

$profile_id = isset($input['profile_id']) ? intval($input['profile_id']) : null;
$text = isset($input['text']) ? $input['text'] : '';
$ai_result = isset($input['ai_result']) ? $input['ai_result'] : null;

if (!$ai_result || !is_array($ai_result)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing ai_result']);
    exit;
}

// DB connection ONLY for saving evaluation
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "lingoland_db";

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB connect failed: '.mysqli_connect_error()]);
    exit;
}

mysqli_report(MYSQLI_REPORT_OFF);

$grammar = isset($ai_result['grammar_score']) ? intval($ai_result['grammar_score']) : 0;
$coherence = isset($ai_result['coherence_score']) ? intval($ai_result['coherence_score']) : 0;
$vocab = isset($ai_result['vocabulary_score']) ? intval($ai_result['vocabulary_score']) : 0;
$overall = isset($ai_result['overall_score']) ? intval($ai_result['overall_score']) : 0;
$summary = isset($ai_result['ai_feedback_summary']) ? $ai_result['ai_feedback_summary'] : '';
$suggestions = isset($ai_result['suggestions']) ? $ai_result['suggestions'] : [];

$writing_prompt = $input['writing_prompt'] ?? null;
$difficulty = $input['difficulty_level'] ?? null;
$metadata = isset($input['prompt_metadata']) ? json_encode($input['prompt_metadata'], JSON_UNESCAPED_UNICODE) : null;

if (!is_array($suggestions)) $suggestions = [];

// Initialize variables
$feedback_id = null;
$log_id = null;
$revision_id = null; // Initialize to prevent undefined variable error

// 1) Insert into writing_feedback
$stmt = $conn->prepare("INSERT INTO writing_feedback (profile_id, submission_text, grammar_score, coherence_score, vocabulary_score, overall_score, ai_feedback_summary, suggestion_json, writing_prompt, difficulty_level, prompt_metadata_json, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
if ($stmt) {
    $sug_json = json_encode($suggestions, JSON_UNESCAPED_UNICODE);
    $stmt->bind_param("isiiiisssss", $profile_id, $text, $grammar, $coherence, $vocab, $overall, $summary, $sug_json, $writing_prompt, $difficulty, $metadata);
    if ($stmt->execute()) {
        $feedback_id = $stmt->insert_id;
    } else {
        $stmt->close();
        mysqli_close($conn);
        echo json_encode(['success' => false, 'message' => 'Failed to save feedback: ' . $stmt->error]);
        exit;
    }
    $stmt->close();
} else {
    mysqli_close($conn);
    echo json_encode(['success' => false, 'message' => 'Failed to prepare statement: ' . $conn->error]);
    exit;
}

// 2) Insert raw log into writing_feedback_log (optional - comment out if table doesn't exist)
/*
$stmt2 = $conn->prepare("INSERT INTO writing_feedback_log (feedback_id, ai_model, prompt_sent, ai_response, created_at) VALUES (?, ?, ?, ?, NOW())");
if ($stmt2) {
    $ai_response_str = json_encode($ai_result, JSON_UNESCAPED_UNICODE);
    $ai_model = 'phi3:mini';
    $stmt2->bind_param("isss", $feedback_id, $ai_model, $text, $ai_response_str);
    if ($stmt2->execute()) {
        $log_id = $stmt2->insert_id;
    }
    $stmt2->close();
}
*/

mysqli_close($conn);

// Return response in the format JavaScript expects
echo json_encode([
    'success' => true, // Changed from 'ok' to 'success'
    'message' => 'Evaluation saved successfully',
    'feedback_id' => $feedback_id,
    'log_id' => $log_id,
    'revision_id' => $revision_id
]);
exit;
?>