<?php
/*****************************************************************
 * update_engagement_daily.php
 * Runs ONCE every time dashboard loads.
 * It calculates:
 *  - total time spent today
 *  - lessons viewed today
 *  - quizzes attempted today
 *  - streak
 *  - engagement score
 *  - mood
 *  - generates 3 AI messages and stores them in ai_engagement_action
 *****************************************************************/

if (session_status() === PHP_SESSION_NONE) session_start();

error_log("=== ENGAGEMENT LOGS DEBUG ===");
error_log("Session user_id: " . ($_SESSION['user_id'] ?? 'NOT SET'));
error_log("Session profile_id: " . ($_SESSION['profile_id'] ?? 'NOT SET'));


// REQUIRE SESSION IDS
if (!isset($_SESSION['user_id'])) return;
if (!isset($_SESSION['profile_id'])) return;

$user_id = intval($_SESSION['user_id']);
$profile_id = intval($_SESSION['profile_id']);

date_default_timezone_set("Asia/Dhaka");
$today = date("Y-m-d");

// DB setup
$conn = new mysqli("localhost", "root", "", "lingoland_db");
if ($conn->connect_error) return;

// -------------------------------
// 1) total minutes today (15-min rule)
// -------------------------------
$sql = "
    SELECT action_time
    FROM activity_log
    WHERE user_id = ?
      AND DATE(action_time) = ?
    ORDER BY action_time ASC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("is", $user_id, $today);
$stmt->execute();
$res = $stmt->get_result();
$rows = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$total_minutes = 0;
for ($i = 1; $i < count($rows); $i++) {
    $t1 = strtotime($rows[$i - 1]["action_time"]);
    $t2 = strtotime($rows[$i]["action_time"]);
    $diff = ($t2 - $t1) / 60;
    if ($diff <= 15) $total_minutes += $diff;
}
$total_minutes = round($total_minutes);

// -------------------------------
// 2) lessons viewed today
// -------------------------------
$sql = "SELECT COUNT(*) AS c FROM activity_log WHERE user_id = ? AND action_type = 'lesson completed' AND DATE(action_time) = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("is", $user_id, $today);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$lessons = intval($row['c'] ?? 0);
$stmt->close();

// -------------------------------
// 3) quizzes attempted today
// -------------------------------
$sql = "SELECT COUNT(*) AS c FROM activity_log WHERE user_id = ? AND action_type = 'quiz completed' AND DATE(action_time) = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("is", $user_id, $today);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$quizzes = intval($row['c'] ?? 0);
$stmt->close();

// -------------------------------
// 4) streak: read recent engagement_log entries
// -------------------------------
$sql = "SELECT session_date FROM engagement_log WHERE profile_id = ? ORDER BY session_date DESC LIMIT 30";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $profile_id);
$stmt->execute();
$res = $stmt->get_result();
$days = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$streak = 1;
$prev = $today;
foreach ($days as $d) {
    $date = $d['session_date'];
    if (date('Y-m-d', strtotime($prev . " -1 day")) === $date) {
        $streak++;
        $prev = $date;
    } else break;
}

// -------------------------------
// 5) engagement score (deterministic)
// -------------------------------
$score_calc =
    ($total_minutes * 1.2) +
    ($lessons * 15) +
    ($quizzes * 10) +
    ($streak * 5);

$engagement_score = intval(round(min(100, $score_calc)));

$mood_state = $engagement_score > 70 ? "motivated" : ($engagement_score >= 40 ? "neutral" : "bored");

// -------------------------------
// 6) UPSERT into engagement_log (profile_id, session_date unique key expected)
// -------------------------------
$upsert = $conn->prepare("
    INSERT INTO engagement_log
        (profile_id, session_date, total_time_minutes, lessons_viewed, quizzes_attempted, streak, engagement_score, mood_state)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
         total_time_minutes = VALUES(total_time_minutes),
         lessons_viewed = VALUES(lessons_viewed),
         quizzes_attempted = VALUES(quizzes_attempted),
         streak = VALUES(streak),
         engagement_score = VALUES(engagement_score),
         mood_state = VALUES(mood_state)
");
$upsert->bind_param("isiiiiss",
    $profile_id, $today, $total_minutes, $lessons, $quizzes, $streak, $engagement_score, $mood_state
);
$upsert->execute();
$upsert->close();

// After upsert, fetch the engagement_log.id for this row
$getId = $conn->prepare("SELECT engagement_id FROM engagement_log WHERE profile_id = ? AND session_date = ? LIMIT 1");
$getId->bind_param("is", $profile_id, $today);
$getId->execute();
$resId = $getId->get_result()->fetch_assoc();
$engagement_id = intval($resId['engagement_id'] ?? 0);
$getId->close();

// -------------------------------
// 7) Generate 3 AI messages and INSERT into ai_engagement_action
// We'll call the existing Flask endpoint /engagement_message_api
// mapping: suggestion -> tip, challenge -> challenge, inspiration -> reminder
// -------------------------------

function call_engagement_ai($prompt) {
    $url = "http://127.0.0.1:5002/engagement_message_api?prompt=" . urlencode($prompt);
    $ctx = stream_context_create(["http" => ["timeout" => 15]]);
     error_log("Calling AI API: " . substr($prompt, 0, 100) . "...");
    $res = @file_get_contents($url, false, $ctx);
    if ($res === false) {
        $error = error_get_last();
        error_log("AI API call failed: " . ($error['message'] ?? 'Unknown error'));
         return null;
    }
    // the endpoint returns plain text
     error_log("AI API response: " . $res);
    return trim($res);
}

$ai_messages = [];

// 1) Suggestion (what to learn next)
$sugg_prompt = "
Generate a 1-sentence recommendation (no emoji) telling this learner what to study next.
User stats: minutes=$total_minutes, lessons_today=$lessons, quizzes_today=$quizzes, streak=$streak, engagement_score=$engagement_score.
Focus: recommend a single actionable item (lesson/flashcards/vocab/review).
";
$ai_suggestion_text = call_engagement_ai($sugg_prompt);
if ($ai_suggestion_text) $ai_messages[] = ['type' => 'tip', 'text' => $ai_suggestion_text];

// 2) Daily Challenge (concrete task)
$challenge_prompt = "
Generate a single-sentence daily challenge (no emoji) appropriate for this learner.
User stats: minutes=$total_minutes, lessons_today=$lessons, quizzes_today=$quizzes, streak=$streak, engagement_score=$engagement_score.
Make the challenge achievable and specific.
";
$ai_challenge_text = call_engagement_ai($challenge_prompt);
if ($ai_challenge_text) $ai_messages[] = ['type' => 'challenge', 'text' => $ai_challenge_text];

// 3) Inspiration (motivational tip)
$inspire_prompt = "
Generate a short motivational one-sentence message (no emoji) to encourage the learner.
User mood: $mood_state, streak=$streak, engagement_score=$engagement_score.
";
$ai_inspire_text = call_engagement_ai($inspire_prompt);
if ($ai_inspire_text) $ai_messages[] = ['type' => 'reminder', 'text' => $ai_inspire_text];

// Insert messages (avoid duplicates: insert latest rows every run)
$ins = $conn->prepare("
    INSERT INTO ai_engagement_action (profile_id, engagement_id, action_type, action_content)
    VALUES (?, ?, ?, ?)
");
foreach ($ai_messages as $m) {
    $type = $m['type'];
    $text = $m['text'];
    // skip if empty
    if (trim($text) === '') continue;
    $ins->bind_param("iiss", $profile_id, $engagement_id, $type, $text);
    $ins->execute();
}
$ins->close();

return;
