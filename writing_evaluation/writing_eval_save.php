<?php
// writing_eval_save.php - Pure API endpoint
header('Content-Type: application/json; charset=utf-8');
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// Only accept POST JSON
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Only POST allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$profile_id = isset($input['profile_id']) ? intval($input['profile_id']) : null;
$text = isset($input['text']) ? $input['text'] : '';
$ai_result = isset($input['ai_result']) ? $input['ai_result'] : null;

if (!$ai_result || !is_array($ai_result)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing ai_result']);
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
    echo json_encode(['error' => 'DB connect failed: '.mysqli_connect_error()]);
    exit;
}

mysqli_report(MYSQLI_REPORT_OFF);

$grammar = isset($ai_result['grammar_score']) ? intval($ai_result['grammar_score']) : 0;
$coherence = isset($ai_result['coherence_score']) ? intval($ai_result['coherence_score']) : 0;
$vocab = isset($ai_result['vocabulary_score']) ? intval($ai_result['vocabulary_score']) : 0;
$overall = isset($ai_result['overall_score']) ? intval($ai_result['overall_score']) : 0;
$summary = isset($ai_result['ai_feedback_summary']) ? $ai_result['ai_feedback_summary'] : '';
$suggestions = isset($ai_result['suggestions']) ? $ai_result['suggestions'] : [];

if (!is_array($suggestions)) $suggestions = [];

// 1) Insert into writing_feedback (FIXED)
$feedback_id = null;
$stmt = $conn->prepare("INSERT INTO writing_feedback (profile_id, submission_text, grammar_score, coherence_score, vocabulary_score, overall_score, ai_feedback_summary, suggestion_json, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
if ($stmt) {
    $sug_json = json_encode($suggestions, JSON_UNESCAPED_UNICODE);
    $stmt->bind_param("isiiiiss", $profile_id, $text, $grammar, $coherence, $vocab, $overall, $summary, $sug_json);
    if ($stmt->execute()) {
        $feedback_id = $stmt->insert_id;
    }
    $stmt->close();
}

// 2) Insert raw log into writing_feedback_log (FIXED)
$log_id = null;
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

// 4) Handle revision saving when improve button is used
if (isset($input['action']) && $input['action'] === 'save_revision' && isset($input['original_text']) && isset($input['revised_text'])) {
    $original_text = $input['original_text'];
    $revised_text = $input['revised_text'];
    
    // Get the latest feedback_id for this profile
    $stmt4 = $conn->prepare("SELECT feedback_id FROM writing_feedback WHERE profile_id = ? ORDER BY submitted_at DESC LIMIT 1");
    if ($stmt4) {
        $stmt4->bind_param("i", $profile_id);
        $stmt4->execute();
        $result = $stmt4->get_result();
        if ($row = $result->fetch_assoc()) {
            $latest_feedback_id = $row['feedback_id'];
            
            // Insert into writing_revision
            $stmt5 = $conn->prepare("INSERT INTO writing_revision (feedback_id, old_text, new_text, improvement_score, revised_at) VALUES (?, ?, ?, ?, NOW())");
            if ($stmt5) {
                // Calculate improvement score (you can enhance this logic)
                $improvement_score = 0; // Default for now
                $stmt5->bind_param("issi", $latest_feedback_id, $original_text, $revised_text, $improvement_score);
                if ($stmt5->execute()) {
                    $revision_id = $stmt5->insert_id;
                    // Add revision_id to response
                    $response['revision_id'] = $revision_id;
                }
                $stmt5->close();
            }
        }
        $stmt4->close();
    }
}
mysqli_close($conn);

echo json_encode([
    'ok' => true,
    'feedback_id' => $feedback_id,
    'log_id' => $log_id,
    'revision_id' => $revision_id
]);
exit;
?>