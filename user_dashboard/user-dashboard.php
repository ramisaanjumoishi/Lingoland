<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login/login_process.php");
    exit();
}

$user_id = $_SESSION['user_id'];



$servername = "localhost";
$username = "root";
$password = "";
$dbname = "lingoland_db";

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    die("DB connection failed: " . mysqli_connect_error());
}

$profile_id = null;
$sql_profile = "SELECT profile_id FROM user_profile WHERE user_id = ?";
$stmt_p = $conn->prepare($sql_profile);
$stmt_p->bind_param("i", $user_id);
$stmt_p->execute();
$res_p = $stmt_p->get_result();
if ($row_p = $res_p->fetch_assoc()) {
    $profile_id = $row_p['profile_id'];
    $_SESSION['profile_id'] = $profile_id; // SET SESSION HERE
}
$stmt_p->close();
require_once __DIR__ . "/update_engagement_logs.php";

/* ---------------------------
   FETCH USER PROFILE DATA
----------------------------*/
$sql = "SELECT first_name, last_name, email, password, profile_picture, score 
        FROM user WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$user = $res->fetch_assoc();

$full_name = $user['first_name'] . " " . $user['last_name'];
// Set profile picture - use img/icon9.png when no profile picture exists
$profile_pic = '../img/icon9.png'; // Default avatar
if (isset($user['profile_picture']) && !empty(trim($user['profile_picture']))) {
    $profile_pic = '../settings/' . $user['profile_picture'];
}

$_SESSION['profile_picture'] = $profile_pic;

/* ---------------------------
   FETCH THEME
----------------------------*/
$sql = "SELECT theme_id FROM sets 
        WHERE user_id = ? ORDER BY set_on DESC LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$theme_row = $res->fetch_assoc();

$theme_id = $theme_row ? $theme_row['theme_id'] : 1;;


// Fetch latest AI engagement messages for this profile
$ai_suggestion = "Welcome — suggestions loading...";
$ai_challenge = "Try today's challenge!";
$ai_inspiration = "Keep going — small steps every day.";

$sql_ai = "SELECT action_type, action_content, triggered_at 
           FROM ai_engagement_action
           WHERE profile_id = ?
           ORDER BY triggered_at DESC
           LIMIT 6";
$stmt_ai = $conn->prepare($sql_ai);
$stmt_ai->bind_param("i", $profile_id);
$stmt_ai->execute();
$res_ai = $stmt_ai->get_result();

while ($r = $res_ai->fetch_assoc()) {
    $t = $r['action_type'];
    $c = $r['action_content'];
    if ($t === 'tip' && $ai_suggestion === "Welcome — suggestions loading...") {
        $ai_suggestion = $c;
    } elseif ($t === 'challenge' && $ai_challenge === "Try today's challenge!") {
        $ai_challenge = $c;
    } elseif ($t === 'reminder' && $ai_inspiration === "Keep going — small steps every day.") {
        $ai_inspiration = $c;
    }
}
$stmt_ai->close();



// decides body class
$body_class = ($theme_id == 2) ? "dark" : "";

/* --- ADD THIS (B: total lessons) --- */
$sql = "SELECT COUNT(DISTINCT lesson_id) AS total_lessons
        FROM progresses_in
        WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$total_lessons = $row['total_lessons'] ?? 0;
/* --- END ADD THIS (B: total lessons) --- */

/* --- ADD THIS (B: total badges) --- */
$sql = "SELECT COUNT(DISTINCT badge_id) AS total_badges
        FROM earned_by
        WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$total_badges = $row['total_badges'] ?? 0;
/* --- END ADD THIS (B: total badges) --- */

/* --- ADD THIS (C: streak calculation) --- */
$streak = 0;

// Fetch latest activity times
$sql = "SELECT DATE(action_time) AS d
        FROM activity_log
        WHERE user_id = ?
          AND action_type = 'login'
        ORDER BY action_time DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();

$dates = [];
while ($row = $res->fetch_assoc()) {
    $dates[] = $row['d'];
}

if (!empty($dates)) {
    $streak = 1;
    for ($i = 0; $i < count($dates) - 1; $i++) {
        $today = strtotime($dates[$i]);
        $prev  = strtotime($dates[$i + 1]);
        $diffDays = ($today - $prev) / 86400;

        if ($diffDays == 1) {
            $streak++;
        } else {
            break;
        }
    }
}
/* --- END ADD THIS (C: streak calculation) --- */

/* ---------------------------
   FETCH HISTORICAL DATA FOR PAST 7 DAYS (DYNAMIC DATES)
----------------------------*/

// Create dynamic date range for the past 7 days (including today)
$dateLabels = [];
$dateValues = []; // Store actual dates for database queries

for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d' , strtotime("-$i days"));
    $dateValues[] = $date;
    $dateLabels[] = date('D', strtotime($date)); // Dynamic day labels
}

// 0. Score earned in past 7 days - FIXED VERSION
$scoreData = array_fill(0, 7, 0);

// Fix the SQL query - make sure it's valid
$sql = "SELECT DATE(created_at) as log_date, SUM(points_earned) as daily_points
        FROM score_log 
        WHERE user_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        GROUP BY DATE(created_at) 
        ORDER BY log_date";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("SQL Error: " . $conn->error); // This will show the actual SQL error
}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();

// Fetch all results into an associative array
$scoreResults = [];
while ($row = $res->fetch_assoc()) {
    $scoreResults[$row['log_date']] = (int)$row['daily_points'];
}

// Map scores to the correct date indices
for ($i = 0; $i < 7; $i++) {
    $checkDate = $dateValues[$i];
    if (isset($scoreResults[$checkDate])) {
        $scoreData[$i] = $scoreResults[$checkDate];
    }
}

// 1. Lessons completed in past 7 days
$lessonsData = array_fill(0, 7, 0);
$sql = "SELECT DATE(completed_on) as date, COUNT(DISTINCT lesson_id) as count 
        FROM progresses_in 
        WHERE user_id = ? AND completed_on >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY DATE(completed_on) 
        ORDER BY date";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $dateIndex = array_search($row['date'], $dateValues);
    if ($dateIndex !== false) {
        $lessonsData[$dateIndex] = $row['count'];
    }
}

// 2. Badges earned in past 7 days - FIXED
$badgesData = array_fill(0, 7, 0);
$sql = "SELECT DATE(earned_on) as date, COUNT(DISTINCT badge_id) as count 
        FROM earned_by 
        WHERE user_id = ? AND earned_on >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY DATE(earned_on) 
        ORDER BY date";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $dateIndex = array_search(date('D', strtotime($row['date'])), $dateLabels);
    if ($dateIndex !== false) {
        $badgesData[$dateIndex] = (int)$row['count'];
    }
}

// 3. Streak calculation for past 7 days (login activity)
$streakData = array_fill(0, 7, 0);
$sql = "SELECT DATE(action_time) as date 
        FROM activity_log 
        WHERE user_id = ? 
          AND action_type = 'login' 
          AND action_time >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY DATE(action_time) 
        ORDER BY date";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();

$loginDates = [];
while ($row = $res->fetch_assoc()) {
    $loginDates[] = $row['date'];
}

// Calculate streak pattern for the graph (rolling 7 days)
$rollingStreak = 0;
for ($i = 0; $i < 7; $i++) {
    $checkDate = $dateValues[$i];
    
    if (in_array($checkDate, $loginDates)) {
        $rollingStreak++;
        $streakData[$i] = $rollingStreak;
    } else {
        $rollingStreak = 0;
        $streakData[$i] = 0;
    }
}

// ✅ ADD THIS LINE: Get the last non-zero value from streakData
$lastStreakValue = 0;
for ($i = 6; $i >= 0; $i--) {
    if ($streakData[$i] > 0) {
        $lastStreakValue = $streakData[$i];
        break;
    }
}

// ✅ Override the original streak with the chart streak value
$streak = $lastStreakValue;

// Convert PHP arrays to JSON for JavaScript
$dateLabelsJson = json_encode($dateLabels);
$scoreDataJson = json_encode($scoreData); // ADD THIS
$lessonsDataJson = json_encode($lessonsData);
$badgesDataJson = json_encode($badgesData);
$streakDataJson = json_encode($streakData);

$recommended_courses = [];

// ----------------- FETCH USER INTERESTS (JOIN with interest table) -----------------

$sql = "SELECT i.interest_name
        FROM user_interest ui
        INNER JOIN interest i ON ui.interest_id = i.interest_id
        WHERE ui.profile_id = ?";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("SQL prepare failed: " . $conn->error);
}

$stmt->bind_param("i", $profile_id);
$stmt->execute();
$interests_res = $stmt->get_result();

$user_interests = [];
if ($interests_res && $interests_res->num_rows > 0) {
    while ($row = $interests_res->fetch_assoc()) {
        $user_interests[] = strtolower(trim($row['interest_name']));
    }
}



// ----------------- FETCH LAST ENROLLED COURSE -----------------
$sql = "SELECT l.course_id
        FROM progresses_in p
        JOIN lesson l ON p.lesson_id = l.lesson_id
        WHERE p.user_id = ?
        ORDER BY p.completed_on DESC LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$last_course_res = $stmt->get_result()->fetch_assoc();
$last_course_id = $last_course_res['course_id'] ?? null;


// -------------------------------------------------------------
// ✅ MACHINE LEARNING RECOMMENDER (ML Hybrid Layer)
// -------------------------------------------------------------

$ml_recommended = [];

try {

    // --- 1) BUILD USER PROFILE FEATURE STRING -----------------
    $user_profile_vector = strtolower(
        ($profile['learning_goal'] ?? '') . ' ' .
        ($profile['target_exam'] ?? '') . ' ' .
        ($profile['proficiency_self'] ?? '') . ' ' .
        ($profile['learning_style'] ?? '') . ' ' .
        ($profile['personality_type'] ?? '') . ' ' .
        implode(' ', $user_interests)
    );

    // --- 2) FETCH ALL COURSES TO SEND TO ML API ---------------
    $courseQuery = "SELECT * FROM course";
    $allCourses = mysqli_query($conn, $courseQuery);

    $course_payload = [];
    if ($allCourses && mysqli_num_rows($allCourses) > 0) {
        while ($c = mysqli_fetch_assoc($allCourses)) {

            // build ML feature string for each course
            $course_vector =
                strtolower($c['title']) . ' ' .
                strtolower($c['description']) . ' ' .
                strtolower($c['tags']) . ' ' .
                strtolower($c['level']) . ' ' .
                str_repeat(" popular ", (int)$c['popularity']);

            $course_payload[] = [
                "course_id" => $c['course_id'],
                "features"  => $course_vector
            ];
        }
    }

    // --- 3) SEND TO PYTHON ML API ------------------------------
    // Python URL: http://127.0.0.1:5000/recommend
    $payload = json_encode([
        "user_vector" => $user_profile_vector,
        "courses"     => $course_payload
    ]);

    $ch = curl_init("http://127.0.0.1:5000/recommend");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    curl_close($ch);

    $ml_result = json_decode($response, true);

    if ($ml_result && isset($ml_result["ranked_courses"])) {
        $ml_recommended = $ml_result["ranked_courses"];
    }

} catch (Exception $e) {
    // ML module failed — silently fallback to rule-based only
}



// -------------------------------------------------------------
// ✅ MERGE RULE-BASED + ML FOR HYBRID TOP RESULTS
// -------------------------------------------------------------

$final_recommendations = [];
$score_map = [];

// 1) RULE BASED → weight = 1.0
foreach ($recommended_courses as $c) {
    $score_map[$c['course_id']] = 1.0;
}

// 2) ML BASED → weight = 1.4 (slightly stronger)
foreach ($ml_recommended as $course) {
    $cid = $course['course_id'];
    $ml_score = $course['score'];

    if (!isset($score_map[$cid])) $score_map[$cid] = 0;
    $score_map[$cid] += 1.4 * $ml_score;
}

// ---- Sort descending by score ----
arsort($score_map);

// ---- Prepare final list (top 4) ----
$final_recommendations = [];

$count = 0;
foreach ($score_map as $cid => $score) {
    if ($count >= 4) break;

    $q = mysqli_query($conn, "SELECT * FROM course WHERE course_id = $cid");
    if ($row = mysqli_fetch_assoc($q)) {
        $final_recommendations[] = $row;
        $count++;
    }
}

// FINAL recommended courses override rule-based one
$recommended_courses = $final_recommendations;

/* ---------------------------
   FETCH LEADERBOARD DATA
----------------------------*/
$leaderboard_data = [];

$sql_leaderboard = "SELECT 
    l.rank,
    l.xp,
    u.user_id,
    u.first_name,
    u.last_name,
    up.age_group
FROM leaderboard l
JOIN user u ON l.user_id = u.user_id
LEFT JOIN user_profile up ON u.user_id = up.user_id
ORDER BY l.rank ASC
LIMIT 10";

$stmt_leaderboard = $conn->prepare($sql_leaderboard);
if ($stmt_leaderboard) {
    $stmt_leaderboard->execute();
    $result_leaderboard = $stmt_leaderboard->get_result();
    
    while ($row = $result_leaderboard->fetch_assoc()) {
        $leaderboard_data[] = $row;
    }
    $stmt_leaderboard->close();
} else {
    // Fallback dummy data if query fails
    $leaderboard_data = [
        ['rank' => 1, 'xp' => 6210, 'first_name' => 'Ayesha', 'last_name' => '', 'age_group' => '18-25'],
        ['rank' => 2, 'xp' => 5100, 'first_name' => 'Kamal', 'last_name' => '', 'age_group' => '26-35'],
        ['rank' => 3, 'xp' => 4900, 'first_name' => 'Rita', 'last_name' => '', 'age_group' => '18-25'],
        ['rank' => 4, 'xp' => 4800, 'first_name' => 'John', 'last_name' => 'Doe', 'age_group' => '26-35'],
        ['rank' => 5, 'xp' => 4700, 'first_name' => 'Sarah', 'last_name' => 'Smith', 'age_group' => '18-25']
    ];
}

require_once __DIR__ . "/../leaderboard/leaderboard.php";
/* ---------------------------------------------------
   FETCH NOTIFICATIONS FOR POPUP MENU
----------------------------------------------------*/
$notif_sql = "
    SELECT a.notification_id, a.is_read, a.is_sent,
           n.title, n.message
    FROM are_sent a
    JOIN notification n ON a.notification_id = n.notification_id
    WHERE a.user_id = ?
    ORDER BY a.is_sent DESC
    LIMIT 30
";

$stmt_notif = $conn->prepare($notif_sql);
$stmt_notif->bind_param("i", $user_id);
$stmt_notif->execute();
$notif_list = $stmt_notif->get_result()->fetch_all(MYSQLI_ASSOC);


/* ---------------------------------------------------
   MARK AS READ (AJAX)
----------------------------------------------------*/
if (isset($_POST['mark_read'])) {
    $nid = (int) $_POST['mark_read'];

    $update_sql = "UPDATE are_sent 
                   SET is_read = 1 
                   WHERE user_id = ? AND notification_id = ?";

    $u_stmt = $conn->prepare($update_sql);
    $u_stmt->bind_param("ii", $user_id, $nid);
    $u_stmt->execute();

    echo "ok";
    exit;
}

/* =============================================================
   PART D — TOP 4 COURSE SPLIT FOR PIE CHART (DYNAMIC)
   ============================================================= */

$courseData = [];

/* Step 1: Count lesson usage grouped by course */
$sql = "
    SELECT 
        l.course_id,
        COUNT(p.lesson_id) AS lesson_count
    FROM progresses_in p
    JOIN lesson l ON p.lesson_id = l.lesson_id
    WHERE p.user_id = ?
    GROUP BY l.course_id
    ORDER BY lesson_count DESC
    LIMIT 4";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();

$topCourses = [];
while ($row = $res->fetch_assoc()) {
    $topCourses[] = $row;
}

/* Step 2: Fetch course names */
$courseData = [];

foreach ($topCourses as $c) {
    $cid = $c['course_id'];
    $count = $c['lesson_count'];

    $sql2 = "SELECT title FROM course WHERE course_id = ?";
    $stmt2 = $conn->prepare($sql2);
    $stmt2->bind_param("i", $cid);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    $row2 = $res2->fetch_assoc();

    $courseData[] = [
        "title" => $row2["title"],
        "lesson_count" => $count
    ];
}

$courseSplitJson = json_encode($courseData);

/* --------------------------------------------------------
   TOP 5 COURSE PROGRESS (PERCENTAGE COMPLETION)
--------------------------------------------------------- */

// 1. Fetch how many lessons the user completed in each course
$sql = "
    SELECT 
        c.course_id,
        c.title,
        COUNT(p.lesson_id) AS completed_lessons
    FROM progresses_in p
    JOIN lesson l ON p.lesson_id = l.lesson_id
    JOIN course c ON l.course_id = c.course_id
    WHERE p.user_id = ?
    GROUP BY c.course_id
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();

$courseProgress = [];

// 2. For each course → find total lessons in that course
while ($row = $res->fetch_assoc()) {

    // count total lessons for this course
    $cid = $row['course_id'];
    $q2 = "SELECT COUNT(*) AS total_lessons FROM lesson WHERE course_id = ?";
    $stmt2 = $conn->prepare($q2);
    $stmt2->bind_param("i", $cid);
    $stmt2->execute();
    $tot = $stmt2->get_result()->fetch_assoc()['total_lessons'];

    // calculate % completion
    $percentage = $tot > 0 ? round(($row['completed_lessons'] / $tot) * 100) : 0;

    $courseProgress[] = [
        'title' => $row['title'],
        'percentage' => $percentage
    ];
}

// 3. Sort highest first and keep top 5
usort($courseProgress, fn($a,$b) => $b['percentage'] - $a['percentage']);
$courseProgress = array_slice($courseProgress, 0, 5);

// 4. Encode for JavaScript
$courseProgressJson = json_encode($courseProgress);

/* --------------------------------------------------------
   WEEKLY ACTIVITY (MINUTES PER DAY, WITH AFK ≤ 15 MIN RULE)
--------------------------------------------------------- */

$sql = "
WITH user_logs AS (
    SELECT
        action_time,
        LEAD(action_time) OVER (
            PARTITION BY DATE(action_time)
            ORDER BY action_time
        ) AS next_time
    FROM activity_log
    WHERE user_id = ?
      AND action_time >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
)
SELECT
    DATE(action_time) AS day,
    SUM(
        CASE
            WHEN next_time IS NOT NULL 
                 AND TIMESTAMPDIFF(MINUTE, action_time, next_time) <= 15
            THEN TIMESTAMPDIFF(MINUTE, action_time, next_time)
            ELSE 0
        END
    ) AS minutes
FROM user_logs
GROUP BY DATE(action_time)
ORDER BY day;
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();

$weeklyData = [];
$today = new DateTime();

// Build last 7 days array
for ($i = 6; $i >= 0; $i--) {
    $day = clone $today;
    $day->modify("-$i day");
    $dateStr = $day->format('Y-m-d');
    $weeklyData[$dateStr] = 0; // default 0 minutes
}

// Fill real data
while ($row = $res->fetch_assoc()) {
    $d = $row['day'];
    $weeklyData[$d] = (int)$row['minutes'];
}

// Convert to arrays for JS
$weeklyLabels = [];
$weeklyMinutes = [];

foreach ($weeklyData as $d => $mins) {
    $weeklyLabels[] = date('D', strtotime($d)); // Mon, Tue, Wed...
    $weeklyMinutes[] = $mins;
}

$weeklyLabelsJson = json_encode($weeklyLabels);
$weeklyMinutesJson = json_encode($weeklyMinutes);

?>


<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Lingoland — Dashboard</title>

  <!-- Fonts & Icons -->
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <style>
    :root {
      --lilac-1: #6a4c93;
      --lilac-2: #9b59b6;
      --bg-light: #f6f7fb;
      --card-light: #ffffff;
      --text-light: #222;
      --bg-dark: #0f1115;
      --card-dark: #16171b;
      --text-dark: #e9eefc;
      --gradient: linear-gradient(135deg, #6a4c93, #9b59b6);
      --muted: #6b6b6b;
      --active-bg: rgba(255,255,255,0.25);
      --active-text: #ffd26f;
    }
    *{box-sizing:border-box}
    body {
      margin: 0;
      font-family: 'Poppins', 'Nunito', system-ui, sans-serif;
      background: var(--bg-light);
      color: var(--text-light);
      transition: background .28s ease, color .28s ease;
      min-height:100vh;
      -webkit-font-smoothing:antialiased;
      -moz-osx-font-smoothing:grayscale;
    }
    body.dark { background: var(--bg-dark); color: var(--text-dark); }

    /* SIDEBAR (same as dashboard) */
    .sidebar{
      position: fixed; left:0; top:0;
      width: 240px; height:100vh;
      background: linear-gradient(180deg,var(--lilac-1),var(--lilac-2));
      color:#fff; padding:22px 14px; overflow:auto;
      display:flex; flex-direction:column; gap:8px;
      z-index:60;
    }
    .sidebar::-webkit-scrollbar { width:6px; }
    .sidebar::-webkit-scrollbar-thumb { background:rgba(255,255,255,0.3); border-radius:8px; }
    .brand { display:flex; gap:12px; align-items:center; margin-bottom:12px; }
    .logo-circle{ width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-weight:800;
      background:linear-gradient(135deg,rgba(255,255,255,0.12),rgba(255,255,255,0.06));
      box-shadow: 0 8px 22px rgba(0,0,0,0.12); font-size:20px;
    }
    nav.menu{ display:flex; flex-direction:column; gap:6px; }
    nav.menu a{
      display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:10px;color:rgba(255,255,255,0.95);text-decoration:none;
      transition:background .18s, transform .15s; font-weight:600;
    }
    nav.menu a:hover{background: rgba(255,255,255,0.08); transform: translateX(6px)}
    nav.menu a.active {
      background:var(--active-bg);
      color:var(--active-text);
      font-weight:700;
      transform:translateX(6px);
    } 
    .sub {display:none; margin-left:18px; flex-direction:column; gap:6px; margin-top:6px}
    .sub a{font-weight:500; font-size:14px; padding-left:18px; color:rgba(255,255,255,0.9)}
       .sub.show {
  display: flex;
  animation: fadeIn .25s ease-in-out;
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(-6px); }
  to { opacity: 1; transform: translateY(0); }
}
    /* HEADER */
    .header {
      position: fixed; left:240px; right:0; top:0; height:64px;
      display:flex; align-items:center; justify-content:space-between;
      padding:10px 20px; background: var(--card-light); border-bottom:1px solid rgba(0,0,0,0.04);
      z-index: 40; transition: background .24s;
    }
    body.dark .header { background: var(--card-dark); border-bottom:1px solid rgba(255,255,255,0.04) }
    .header .left {display:flex;align-items:center; gap:14px}
    .header .right { display:flex; align-items:center; gap:12px; position:relative }

    .header .info {line-height:1}
    .header .info strong{display:block}
    .icon-btn{
      background:transparent;border:0;padding:8px;border-radius:8px;cursor:pointer;font-size:16px;
    }

    /* Settings infinite spin */
    #settingsBtn { font-size:18px; color:var(--muted); }
    #settingsBtn { animation: rotating 8s linear infinite; }
    @keyframes rotating { 0%{ transform:rotate(0deg) } 100%{ transform:rotate(360deg) } }

    /* small popups */
    .popup {
      position:absolute; top:62px; right:20px; width:280px; display:none; background:var(--card-light); border-radius:10px;
      box-shadow:0 12px 30px rgba(0,0,0,0.12); padding:10px; z-index:80;
    }
    body.dark .popup { background:var(--card-dark) }
    .popup.show { display:block }

    /* MAIN */
    main{ margin-left:240px; padding:94px 28px 48px 28px; min-height:100vh; }

    h1{ margin:0; font-size:22px; font-weight:700; }

    /* TOP STAT CARDS */
    .stats { display:grid; grid-template-columns: repeat(4,1fr); gap:18px; margin-top:18px; }
    .card{
      border-radius:14px; padding:14px; background:var(--card-light); box-shadow:0 8px 28px rgba(11,11,12,0.06);
      transition: transform .22s ease, background .24s ease, opacity .36s ease, transform .36s ease;
      opacity:0; transform:translateY(12px);
    }
    body.dark .card{ background:var(--card-dark) }
    .card h4{ margin:0 0 6px 0; font-size:14px }
    .stat-large{ font-weight:800; font-size:20px; margin-top:6px }
    .mini-canvas{ width:120px; height:46px; }

    .card.enter { opacity:1; transform:translateY(0); }

    .card:hover{ transform: translateY(-6px) }

    /* charts row */
    .charts-row{ display:flex; gap:18px; margin-top:20px; align-items:flex-start; flex-wrap:wrap; }
    .chart-box{ flex:1; min-width:280px; background:var(--card-light); padding:14px; border-radius:12px; box-shadow:0 8px 26px rgba(11,11,12,0.04); transition:opacity .36s ease, transform .36s ease; opacity:0; transform:translateY(14px) }
    body.dark .chart-box { background:var(--card-dark) }
    .chart-box.enter{ opacity:1; transform:translateY(0); }

    /* --------------------------------------------------------------------
       MODIFIED: Course card styling (kept mostly your structure, improved look)
       -------------------------------------------------------------------- */
    .courses-grid { display:flex; gap:14px; margin-top:18px; flex-wrap:wrap }
    /* Modified: more polished, subtle gradient, icon, hover scale, entrance */
.course-card {
  width: 240px;
  min-height: 100px;
  padding: 16px;
  border-radius: 18px;
  background: linear-gradient(135deg, #faf5ff, #f5f0ff);
  border: 2px solid rgba(155, 89, 182, 0.25);
  transition: transform .28s ease, box-shadow .28s ease, opacity .36s ease;
  position: relative;
  overflow: hidden;
  opacity: 0;
  transform: translateY(12px) scale(.995);
}
.course-card.enter { opacity: 1; transform: translateY(0) scale(1); }
body.dark .course-card {
  background: linear-gradient(135deg,#20132d,#3a2951);
  border: 2px solid rgba(255,255,255,0.08);
}
.course-card:hover {
  transform: translateY(-8px) scale(1.03);
  box-shadow: 0 20px 40px rgba(106,76,147,0.25);
  border-color: #9b59b6;
}
.course-card .cc-icon {
  width: 46px;
  height: 46px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--gradient);
  color: #fff;
  position: absolute;
  left: 12px;
  top: 12px;
  font-size: 20px;
}
    .course-card .interest { font-size:12px; color:var(--muted); margin-top:6px; }
    
    .course-card .cc-content { margin-left: 56px; }
    /* -------------------------------------------------------------------- */

    /* --------------------------------------------------------------------
       MODIFIED: Leaderboard styling (rank gradients + nicer badges)
       -------------------------------------------------------------------- */
    .leaderboard{ margin-top:24px; }
    .leader-row{
      display:flex;align-items:center;justify-content:space-between;
      background:var(--card-light);padding:12px;border-radius:12px;margin-top:10px;
      transition: transform .32s ease, box-shadow .32s ease, opacity .36s ease;
      box-shadow:0 6px 18px rgba(0,0,0,0.04);
      opacity:0; transform:translateY(10px);
    }
    .leader-row:nth-child(1) {
  background: linear-gradient(135deg,#fff1d0,#ffe29a);
}
.leader-row:nth-child(2) {
  background: linear-gradient(135deg,#e3f2ff,#b8dfff);
}
.leader-row:nth-child(3) {
  background: linear-gradient(135deg,#ffe4ec,#ffb3c6);
}
body.dark .leader-row:nth-child(1) {
  background: linear-gradient(135deg,#3a2e19,#4a3c22);
}
body.dark .leader-row:nth-child(2) {
  background: linear-gradient(135deg,#1b2e47,#294b63);
}
body.dark .leader-row:nth-child(3) {
  background: linear-gradient(135deg,#432b3a,#623c4c);
}

    .leader-row.enter { opacity:1; transform:translateY(0); }
    body.dark .leader-row{ background:var(--card-dark); }
    .leader-row:hover{ transform: translateY(-6px) scale(1.01); box-shadow:0 18px 46px rgba(0,0,0,0.12) }
    .rank-badge{
      width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;
      font-weight:800;color:#fff;font-size:16px; box-shadow: 0 8px 20px rgba(0,0,0,0.08);
    }
    .rank1{background:linear-gradient(135deg,#ffd26f,#ff9a00);}
    .rank2{background:linear-gradient(135deg,#a2d2ff,#6ec1e4);}
    .rank3{background:linear-gradient(135deg,#ffb3c6,#ff6392);}
    .rank-other{background:linear-gradient(135deg,#ccc,#999);}
    /* -------------------------------------------------------------------- */

    /* settings panel */
    .settings-panel{ position: fixed; right:-460px; top:0; width:420px; height:100vh; background:var(--card-light);
      box-shadow:-16px 0 40px rgba(0,0,0,0.14); padding:18px; transition:right .32s ease; z-index:90; }
    .settings-panel.open{ right:0; }
    .settings-close{ position:absolute; top:10px; right:14px; background:none; border:none; color:var(--muted); font-size:20px; cursor:pointer; }
    body.dark .settings-panel{ background:var(--card-dark); }

    /* Updated settings panel styles */
.settings-panel {
  width: 420px; 
  padding: 28px;
  overflow-y: auto;
}
.settings-profile {
  text-align: center;
  margin-top: 40px;
}
.settings-profile h3 {
  margin: 10px 0 4px 0;
  font-weight: 700;
  color: var(--lilac-1);
}
.settings-profile p {
  color: var(--muted);
  font-size: 14px;
}

.profile-photo {
  position: relative;
  display: inline-block;
}
.profile-photo img {
  width: 100px;
  height: 100px;
  border-radius: 50%;
  object-fit: cover;
  box-shadow: 0 6px 20px rgba(0,0,0,0.1);
}
.edit-photo {
  position: absolute;
  bottom: 6px;
  right: 6px;
  background: var(--gradient);
  border: none;
  color: #fff;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  cursor: pointer;
  font-size: 12px;
  transition: transform .2s;
}
.edit-photo:hover { transform: scale(1.1); }

.settings-section {
  margin-top: 24px;
}
.settings-section h4 {
  color: var(--lilac-1);
  margin-bottom: 8px;
  font-size: 16px;
  border-bottom: 1px solid rgba(0,0,0,0.06);
  padding-bottom: 4px;
}
.field-group {
  display: flex;
  align-items: center;
  margin-top: 10px;
  background: var(--card-light);
  border-radius: 8px;
  padding: 6px;
  box-shadow: 0 3px 8px rgba(0,0,0,0.04);
  
}
body.dark .field-group {
  background: var(--card-dark);
  
}
.field-group input {
  flex: 1;
  border: none;
  background: transparent;
  padding: 10px;
  font-size: 14px;
  color: inherit;
}
.field-group input:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
.edit-btn {
  background: var(--gradient);
  border: none;
  color: #fff;
  border-radius: 6px;
  width: 32px;
  height: 32px;
  cursor: pointer;
  transition: transform .2s, background .3s;
}
.edit-btn:hover {
  transform: scale(1.1);
}

/* Settings Panel Input Styling */
.settings-panel input[type="text"],
.settings-panel input[type="email"],
.settings-panel input[type="password"] {
  width: 100%;
  padding: 10px 12px;
  border-radius: 10px;
  border: 2px solid transparent;
  background: linear-gradient(#fff, #fff) padding-box,
              linear-gradient(90deg, #6a4c93, #9b59b6, #ffd26f) border-box;
  font-family: 'Poppins', sans-serif;
  font-size: 14px;
  color: var(--text-light);
  transition: all 0.3s ease;
}

/* Dark mode support */
body.dark .settings-panel input {
  background: linear-gradient(#16171b, #16171b) padding-box,
              linear-gradient(90deg, #b57aff, #9b59b6, #ffd26f) border-box;
  color: #e9eefc;
}

/* Hover + focus effects */
.settings-panel input:hover,
.settings-panel input:focus {
  outline: none;
  transform: scale(1.02);
  box-shadow: 0 0 12px rgba(155, 89, 182, 0.5);
  background: linear-gradient(#fff, #fff) padding-box,
              linear-gradient(135deg, #9b59b6, #ffd26f) border-box;
}

body.dark .settings-panel input:hover,
body.dark .settings-panel input:focus {
  box-shadow: 0 0 14px rgba(181, 122, 255, 0.6);
}

.settings-panel .edit-btn {
  background: none;
  border: none;
  color: var(--lilac-2);
  cursor: pointer;
  font-size: 15px;
  transition: transform 0.2s ease, color 0.2s ease;
}

.settings-panel .edit-btn:hover {
  color: #ffd26f;
  transform: scale(1.1);
}



    /* chatbot */
    .chatbot{ position: fixed; right:30px; bottom:22px; z-index:120; display:flex; flex-direction:column; align-items:flex-end; gap:8px; }
    .chatbot-btn{ width:60px; height:60px; border-radius:50%; border:0; background:var(--gradient); color:white; font-size:22px; cursor:pointer; box-shadow:0 12px 36px rgba(0,0,0,0.18); }
    .chat-window {
  width: 360px;
  min-height: 360px;
  background: radial-gradient(circle at top left, #2b163e, #1b102c 80%);
  color: #f4f0ff;
  border-radius: 16px;
  padding: 16px;
  box-shadow: 0 25px 50px rgba(0,0,0,0.25);
  display: none;
  opacity: 0;
  transform: translateY(10px);
  transition: all .4s ease;
  overflow: hidden;
  position: relative;
}
.chat-window.show { display:block; opacity:1; transform:translateY(0); }
.chat-window::before {
  content:'';
  position:absolute; top:0; left:0; right:0; height:2px;
  background:linear-gradient(90deg,#b57aff,#9b59b6,#b57aff);
  animation: moveLine 3s linear infinite;
}
@keyframes moveLine { 0%{background-position:0 0}100%{background-position:200% 0} }

.chat-header {
  font-weight:700;
  font-size:18px;
  margin-bottom:6px;
}
.chat-example {
  background:rgba(255,255,255,0.08);
  padding:6px 10px;
  border-radius:8px;
  font-size:13px;
  margin-top:6px;
  cursor:pointer;
  transition:background .2s;
}
.chat-example:hover { background:rgba(255,255,255,0.15); }

/* Loading orbs animation */
.loading-orbs {
  display:flex;gap:6px;margin:8px auto 12px auto;justify-content:center;
}
.loading-orbs div {
  width:8px;height:8px;border-radius:50%;
  background:#b57aff;
  animation: pulse 1s infinite ease-in-out;
}
.loading-orbs div:nth-child(2){animation-delay:.2s;}
.loading-orbs div:nth-child(3){animation-delay:.4s;}
@keyframes pulse {
  0%,100%{opacity:.3;transform:scale(.9)}
  50%{opacity:1;transform:scale(1.2)}
}

    .chat-window.show{ display:block; opacity:1; transform:translateY(0); }
    body.dark .chat-window{ background:var(--card-dark); }

    /* page entrance transitions for a nicer feel */
    .stagger { opacity:0; transform:translateY(12px); transition: all .42s cubic-bezier(.2,.9,.2,1); }
    .stagger.show { opacity:1; transform:translateY(0); }

    /* responsive */
    @media (max-width: 980px){
      .sidebar{ width:72px; padding:14px }
      .header{ left:72px }
      main{ margin-left:72px; padding:86px 12px }
      .stats{ grid-template-columns: repeat(2,1fr) }
      .courses-grid{ justify-content:flex-start }
    }

  /* ----------------------------------------------
   AI Writing Panel (Dashboard Add-on)
------------------------------------------------*/
.ai-writing-panel {
  margin-top: 32px;
}

.ai-title {
  margin-bottom: 12px;
  font-weight: 700;
  font-size: 20px;
}

.ai-grid {
  display: flex;
  gap: 16px;
  flex-wrap: wrap;
}

/* AI Cards (matching course cards style) */
.ai-card {
  width: 300px;
  min-height: 120px;
  padding: 18px;
  border-radius: 18px;
  background: linear-gradient(135deg, #faf5ff, #f5f0ff);
  border: 2px solid rgba(155, 89, 182, 0.25);
  display: flex;
  gap: 14px;
  transition: transform .28s ease, box-shadow .28s ease, opacity .36s ease;
  opacity: 0;
  transform: translateY(14px) scale(.98);
}

.ai-card.enter {
  opacity: 1;
  transform: translateY(0) scale(1);
}

body.dark .ai-card {
  background: linear-gradient(135deg,#20132d,#3a2951);
  border: 2px solid rgba(255,255,255,0.08);
}

.ai-card:hover {
  transform: translateY(-8px) scale(1.03);
  box-shadow: 0 22px 40px rgba(106,76,147,0.25);
  border-color: #9b59b6;
}

/* Icons */
.ai-icon {
  width: 50px;
  height: 50px;
  border-radius: 12px;
  background: var(--gradient);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 24px;
  color: #fff;
}

/* Text */
.ai-content strong {
  font-size: 16px;
}

.ai-desc {
  font-size: 13px;
  margin-top: 4px;
  color: var(--muted);
}

/* Button */
.ai-btn {
  margin-top: 10px;
  padding: 6px 12px;
  border-radius: 8px;
  background: var(--lilac-1);
  color: #fff;
  border: none;
  cursor: pointer;
  transition: background .22s ease, transform .2s ease;
}

.ai-btn:hover {
  background: var(--lilac-2);
  transform: translateY(-2px);
}

/* Add this to your existing CSS */
.ai-writing-panel.stagger {
  opacity: 0;
  transform: translateY(20px);
  transition: opacity 0.5s ease, transform 0.5s ease;
}

.ai-writing-panel.stagger.enter {
  opacity: 1;
  transform: translateY(0);
}

.ai-card {
  /* Your existing styles */
  opacity: 0;
  transform: translateY(14px) scale(.98);
  transition: transform .28s ease, box-shadow .28s ease, opacity .36s ease;
}

.ai-card.enter {
  opacity: 1;
  transform: translateY(0) scale(1);
}

    /* --------------------------------------------------------------------
       MODIFIED: Leaderboard styling (rank gradients + alternating colors)
       -------------------------------------------------------------------- */
    .leaderboard{ margin-top:24px; }
    .leader-row{
      display:flex;align-items:center;justify-content:space-between;
      background:var(--card-light);padding:12px;border-radius:12px;margin-top:10px;
      transition: transform .32s ease, box-shadow .32s ease, opacity .36s ease;
      box-shadow:0 6px 18px rgba(0,0,0,0.04);
      opacity:0; transform:translateY(10px);
    }
    /* First 3 ranks with special colors */
    .leader-row:nth-child(1) {
      background: linear-gradient(135deg,#fff1d0,#ffe29a);
    }
    .leader-row:nth-child(2) {
      background: linear-gradient(135deg,#e3f2ff,#b8dfff);
    }
    .leader-row:nth-child(3) {
      background: linear-gradient(135deg,#ffe4ec,#ffb3c6);
    }
    /* Alternating colors for ranks 4+ */
    .leader-row:nth-child(4),
    .leader-row:nth-child(6),
    .leader-row:nth-child(8),
    .leader-row:nth-child(10) {
      background: linear-gradient(135deg,#f0f4ff,#CEDFCF);
    }
    .leader-row:nth-child(5),
    .leader-row:nth-child(7),
    .leader-row:nth-child(9) {
      background: linear-gradient(135deg,#f9f2ff,#F3D6E0);
    }
    
    /* Dark mode support */
    body.dark .leader-row:nth-child(1) {
      background: linear-gradient(135deg,#3a2e19,#4a3c22);
    }
    body.dark .leader-row:nth-child(2) {
      background: linear-gradient(135deg,#1b2e47,#294b63);
    }
    body.dark .leader-row:nth-child(3) {
      background: linear-gradient(135deg,#432b3a,#623c4c);
    }
    body.dark .leader-row:nth-child(4),
    body.dark .leader-row:nth-child(6),
    body.dark .leader-row:nth-child(8),
    body.dark .leader-row:nth-child(10) {
      background: linear-gradient(135deg,#1a1f2e,#22283a);
    }
    body.dark .leader-row:nth-child(5),
    body.dark .leader-row:nth-child(7),
    body.dark .leader-row:nth-child(9) {
      background: linear-gradient(135deg,#252038,#2d2645);
    }

    .leader-row.enter { opacity:1; transform:translateY(0); }
    body.dark .leader-row{ background:var(--card-dark); }
    .leader-row:hover{ transform: translateY(-6px) scale(1.01); box-shadow:0 18px 46px rgba(0,0,0,0.12) }
    
    .rank-badge{
      width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;
      font-weight:800;color:#fff;font-size:16px; box-shadow: 0 8px 20px rgba(0,0,0,0.08);
    }
    .rank1{background:linear-gradient(135deg,#ffd26f,#ff9a00);}
    .rank2{background:linear-gradient(135deg,#a2d2ff,#6ec1e4);}
    .rank3{background:linear-gradient(135deg,#ffb3c6,#ff6392);}
    .rank-other{background:linear-gradient(135deg,#ccc,#999);}

/* --- CHATBOT ENHANCED DESIGN --- */

.chatbot {
  position: fixed;
  right: 30px;
  bottom: 22px;
  z-index: 120;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 8px;
}

.chatbot-btn {
  width: 62px;
  height: 62px;
  border-radius: 50%;
  border: 0;
  background: var(--gradient);
  color: white;
  font-size: 24px;
  cursor: pointer;
  box-shadow: 0 12px 36px rgba(0,0,0,0.18);
}

/* CHAT WINDOW WIDER + BETTER FONT SIZE */
.chat-window {
  width: 480px;                      /*  ⬅ wider */
  min-height: 420px;
  background: linear-gradient(145deg,#24132f,#1b102c 70%);
  color: #f4f0ff;
  border-radius: 16px;
  padding: 18px;
  box-shadow: 0 25px 50px rgba(0,0,0,0.25);
  display: none;
  opacity: 0;
  transform: translateY(10px);
  transition: all .4s ease;
  overflow: hidden;
  position: relative;
}


.chat-window.show { display:block; opacity:1; transform:translateY(0); }

.chat-header {
  font-weight:700;
  font-size:20px;
  margin-bottom:10px;
}

/* Example prompts smaller + clickable */
.chat-example {
  background: rgba(255,255,255,0.09);
  padding: 8px 10px;
  border-radius: 8px;
  font-size: 13px;
  cursor: pointer;
  transition: .25s ease;
}
.chat-example:hover { background: rgba(255,255,255,0.16); }

#chatBody {
  height: 250px;
  overflow-y: auto;
  margin-top: 14px;
  padding-right: 6px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

/* user bubble */
.msg-user {
  align-self: flex-end;
  background: #ffffff22;
  padding: 10px 14px;
  border-radius: 12px;
  max-width: 80%;
  font-size: 14px;
  color: #fff;
  backdrop-filter: blur(4px);
}

/* ai bubble */
.msg-ai {
  align-self: flex-start;
  background: #b57aff25;
  padding: 10px 14px;
  border-radius: 12px;
  max-width: 80%;
  font-size: 14px;
  color: #f7e9ff;
  border-left: 2px solid #b57aff;
}

/* Loading dots */
.loading-orbs div{
  width:7px; height:7px;
}

/* ----------------------------------------
   NOTIFICATION POPUP DESIGN
-------------------------------------------*/
.notif-popup {
    position: absolute;
    top: 64px;
    right: 80px;
    width: 360px;
    background: var(--card-light);
    border-radius: 18px;
    padding: 0;
    box-shadow: 0 12px 30px rgba(0,0,0,0.15);
    display: none;
    flex-direction: column;
    max-height: 480px;
    overflow: hidden;
    z-index: 200;
}

body.dark .notif-popup {
    background: var(--card-dark);
    box-shadow: 0 12px 30px rgba(255,255,255,0.07);
}

.notif-header {
    padding: 16px;
    font-size: 17px;
    font-weight: 700;
    border-bottom: 1px solid rgba(0,0,0,0.1);
    background-color:rgba(225, 215, 215, 0.69);
}

body.dark .notif-header {
    border-bottom: 1px solid rgba(255,255,255,0.1);
    background-color:rgba(43, 10, 231, 0.22);
}

.notif-body {
    overflow-y: auto;
    max-height: 420px;
}

.notif-item {
    padding: 16px;
    display: flex;
    justify-content: space-between;
    border-bottom: 1px solid rgba(0,0,0,0.05);
    cursor: pointer;
    transition: background .2s;
}

.notif-item:hover {
    background: rgba(0,0,0,0.04);
}

body.dark .notif-item:hover {
    background: rgba(255,255,255,0.05);
}

.notif-item.unread {
    background: rgba(155,89,182,0.06);
}

body.dark .notif-item.unread {
    background: rgba(155,89,182,0.18);
}

.notif-title {
    font-weight: 600;
    font-size: 14px;
}

.notif-msg {
    font-size: 13px;
    opacity: 0.8;
    margin-top: 4px;
}

.notif-meta {
    text-align: right;
    font-size: 12px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.notif-mark {
    color: #6a4c93;
    font-size: 10px;
}

.notif-empty {
    text-align: center;
    padding: 24px;
    opacity: .6;
}

.notif-popup {
    position: absolute;
    top: 65px;
    right: 60px;
    width: 380px;
    background: var(--card-light);
    border-radius: 18px;
    box-shadow: 0 18px 40px rgba(0,0,0,0.25);
    overflow: hidden;
    display: none;
    flex-direction: column;
    max-height: 520px;
    z-index: 2000;
}

.notif-body {
    overflow-y: auto;
    max-height: 460px;
    padding: 0;
}

.notif-body::-webkit-scrollbar {
    width: 6px;
}
.notif-body::-webkit-scrollbar-thumb {
    background: #9b59b6;
    border-radius: 10px;
}

.notif-item {
    display: flex;
    gap: 12px;
    padding: 14px;
    border-bottom: 1px solid rgba(0,0,0,0.05);
    transition: background .2s ease;
    cursor: pointer;
}

.notif-item:hover {
    background: rgba(155,89,182,0.08);
}

.notif-item.unread {
    background: rgba(155,89,182,0.12);
}

.notif-icon {
    width: 40px;
    height: 40px;
    border-radius: 12px;
    display: flex;
    justify-content: center;
    align-items: center;
    color: white;
}

.icon-1 { background: #6c5ce7; }   /* course */
.icon-2 { background: #a55eea; }   /* lesson */
.icon-3 { background: #00b894; }   /* message */
.icon-4 { background: #fdcb6e; }   /* badge */
.icon-5 { background: #e17055; }   /* leaderboard */

.mark-read-btn {
    background: transparent;
    color: #9b59b6;
    border: none;
    font-size: 12px;
    cursor: pointer;
    padding: 0;
}
.mark-read-btn:hover {
    color: #6a4c93;
    text-decoration: underline;
}

  </style>
</head>
<body class ="<?php echo $body_class; ?>">

  <!-- SIDEBAR -->
  <aside class="sidebar" aria-label="Main sidebar">
    <div class="brand">
      <div class="logo-circle">L</div>
      <div style="line-height:1">
        <div style="font-weight:800">Lingoland</div>
      </div>
    </div>

    <nav class="menu" aria-label="Main menu">
      <a href="../user_dashboard/user-dashboard.php" class="active"><i class="fas fa-home"></i> <span>Home</span></a>
      <a href="../courses/courses.php"><i class="fas fa-book"></i> <span>Courses</span></a>
      <a href="../leaderboard/view_leaderboard.php"><i class="fas fa-trophy"></i> <span>Leaderboard</span></a>

      <a id="flashToggle"><span><i class="fas fa-th-large"></i> Flashcards</span><i class="fas fa-chevron-right"></i></a>
      <div class="sub" id="flashSub">
        <a href="../user_flashcards/user_flashcards.php">Review Flashcards</a>
        <a href="../user_flashcards/create_flashcard.php">Add New Flashcard</a>
         <a href="../user_flashcards/bookmark_flashcard.php">Bookmarked Flashcard</a>
      </div>

      <a id="vocabToggle"><span><i class="fas fa-language"></i> Vocabulary</span><i class="fas fa-chevron-right"></i></a>
      <div class="sub" id="vocabSub">
        <a href="../user_dashboard_words/user_dashboard_words.php">Study New Words</a>
        <a href="../user_dashboard_words/add_words.php">Your Dictionary</a>
      </div>

      <a href="../user_badge/user_badge.php"><i class="fas fa-award"></i> <span>Badge</span></a>
      <a href="../user_certificate/user_certificate.php"><i class="fas fa-certificate"></i> <span>Certificates</span></a>
      <a id="writingToggle"><i class="fas fa-robot"></i> AI Writing Assistant<i class="fas fa-chevron-right"></i></a>
    <div class="sub" id="writingSub">
      <a href="../writing_evaluation/writing_evaluation.php">Evaluate Writing</a>
      <a href="../writing_evaluation/my_writing.php">My Writings</a>
    </div>
      <a href="../user_dashboard/logout.php"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a>
    </nav>
  </aside>

  <!-- HEADER -->
  <header class="header" role="banner">
    <div class="left">
      <div class="logo" style="gap:12px;">
        <div class="logo-circle" style="width:44px;height:44px;font-size:18px">L</div>
      </div>
    </div>
    <div class="info" style="font-size: larger;"><strong>USER DASHBOARD</strong><small style= "color:var(--muted)" ></small></div>

    <div class="right">
     <button class="icon-btn" id="notifBtn">🔔<span class="badge" id="notifBadge"></span></button>
    <div id="notifPopup" class="notif-popup">
    <div class="notif-header">Notifications</div>

    <div class="notif-body">

        <?php foreach ($notif_list as $n): ?>

        <div class="notif-item <?php echo $n['is_read'] ? 'read' : 'unread'; ?>"
             data-id="<?php echo $n['notification_id']; ?>">

            <div class="notif-icon icon-<?php echo $n['notification_id']; ?>">
                <?php
                    $icons = [
                        1 => "fa-graduation-cap", // course
                        2 => "fa-book",           // lesson
                        3 => "fa-envelope",       // message
                        4 => "fa-award",          // badge
                        5 => "fa-trophy",         // leaderboard
                    ];
                ?>
                <i class="fas <?php echo $icons[$n['notification_id']]; ?>"></i>
            </div>

            <div class="notif-content">
                <div class="notif-title"><?php echo htmlspecialchars($n['title']); ?></div>
                <div class="notif-msg"><?php echo htmlspecialchars($n['message']); ?></div>
            </div>

            <div class="notif-meta">
                <span class="notif-time">
                    <?php echo date("M d, h:i A", strtotime($n['is_sent'])); ?>
                </span>

                <button class="mark-read-btn" 
                        data-id="<?php echo $n['notification_id']; ?>">
                    <?php echo $n['is_read'] ? "Seen" : "Mark"; ?>
                </button>
            </div>

        </div>

        <?php endforeach; ?>

    </div>
</div>

      <button id="settingsBtn" class="icon-btn" title="Settings (spinning)"><i class="fas fa-cog"></i></button>

      <button id="themeBtn" class="icon-btn" title="Toggle theme">🌓</button>

      <img id="profilePic" src="<?php echo $profile_pic; ?>" alt="profile" style="width:46px;height:46px;border-radius:10px;cursor:pointer;border:2px solid rgba(0,0,0,0.06)">
    </div>
  </header>

  <!-- SETTINGS PANEL (right slide-in) -->
 <!-- SETTINGS PANEL (updated with profile editing UI) -->
<aside id="settingsPanel" class="settings-panel" aria-hidden="true">
  <button class="settings-close" id="settingsClose"><i class="fas fa-times"></i></button>

  <div class="settings-profile">
    <div class="profile-photo">
      <img id="settingsProfilePic" src="<?php echo $profile_pic; ?>" alt="Profile Picture">
      <button id="editPhotoBtn" class="edit-photo"><i class="fas fa-pen"></i></button>
      <input type="file" id="photoInput" accept="image/*" hidden>
    </div>
    <h3 id="userFullName"><?php echo $full_name; ?></h3>
    <p id="userEmail"><?php echo $user['email']; ?></p>
  </div>

  <div class="settings-section">
    <h4>Profile</h4>
    <div class="field-group">
      <input type="text" id="firstName" value="<?php echo $user['first_name']; ?>"disabled>
      <button class="edit-btn" data-field="firstName"><i class="fas fa-pen"></i></button>
    </div>
    <div class="field-group">
      <input type="text" id="lastName" value="<?php echo $user['last_name']; ?>" disabled>
      <button class="edit-btn" data-field="lastName"><i class="fas fa-pen"></i></button>
    </div>
  </div>

  <div class="settings-section">
    <h4>Account</h4>
    <div class="field-group">
      <input type="email" id="emailField" value="<?php echo $user['email']; ?>" disabled>
      <button class="edit-btn" data-field="emailField"><i class="fas fa-pen"></i></button>
    </div>
    <div class="field-group">
      <input type="password" id="passwordField" value="<?php echo $user['password']; ?>"disabled>
      <button class="edit-btn" data-field="passwordField"><i class="fas fa-pen"></i></button>
    </div>
  </div>
</aside>

  <!-- MAIN -->
  <main>
    <h1>Welcome, Ramisa! 🌸 Continue your language expedition</h1>

    <!-- STAT CARDS -->
    <section class="stats" aria-label="Key statistics">
      <div class="card"><h4>Total Score</h4><div class="stat-large"><?php echo $user['score']; ?></div><div style="margin-top:10px;color:var(--muted)">Overall points</div><div style="margin-top:10px"><canvas id="mini1" class="mini-canvas"></canvas></div></div>
      <div class="card"><h4>Total Lessons</h4><div class="stat-large"><?php echo $total_lessons; ?></div><div style="margin-top:10px;color:var(--muted)">Completed lessons</div><div style="margin-top:10px"><canvas id="mini2" class="mini-canvas"></canvas></div></div>
      <div class="card"><h4>Streak</h4><div class="stat-large"><?php echo $streak . " days"; ?></div><div style="margin-top:10px;color:var(--muted)">Daily practice streak</div><div style="margin-top:10px"><canvas id="mini3" class="mini-canvas"></canvas></div></div>
      <div class="card"><h4>Badges</h4><div class="stat-large"><?php echo $total_badges; ?></div><div style="margin-top:10px;color:var(--muted)">Achievements unlocked</div><div style="margin-top:10px"><canvas id="mini4" class="mini-canvas"></canvas></div></div>
    </section>

    <!-- Charts row -->
    <section class="charts-row" aria-label="Progress charts">
      <div class="chart-box"><h4 style="margin:0 0 8px 0">Weekly Time Spent</h4><small style="color:var(--muted)">Average minutes per day × 7</small><div style="height:180px;margin-top:12px"><canvas id="lineChart"></canvas></div></div>

      <div class="chart-box" style="width:360px"><h4 style="margin:0 0 8px 0">Course Type Split</h4><small style="color:var(--muted)">Time share by category</small><div style="height:180px;margin-top:12px"><canvas id="pieChart"></canvas></div></div>

      <div class="chart-box" style="width:420px"><h4 style="margin:0 0 8px 0">Course Progress (Top)</h4><small style="color:var(--muted)">Top 5 courses by completion</small><div style="height:180px;margin-top:12px"><canvas id="barChart"></canvas></div></div>
    </section>

    <!-- 🔥 AI Writing Assistant Panel -->
<section class="ai-writing-panel stagger">
  <h3 class="ai-title">Smart AI Suggestions</h3>

  <div class="ai-grid">

    <!-- 📌 Daily AI Suggestion -->
    <div class="ai-card" id="aiPromptCard">
      <div class="ai-icon">✍️</div>
      <div class="ai-content">
        <strong>Tips of the Day</strong>
        <p id="aiPromptText" class="ai-desc"><?php echo htmlspecialchars($ai_suggestion); ?></p>
      </div>
    </div>

    <!-- 🎯 Daily Challenge -->
    <div class="ai-card" id="aiChallengeCard">
      <div class="ai-icon">📘</div>
      <div class="ai-content">
        <strong>Daily Challenge</strong>
        <p id="aiChallengeText" class="ai-desc"><?php echo htmlspecialchars($ai_challenge); ?></p>
        <button class="ai-btn" id="startChallengeBtn">Start Challenge</button>
      </div>
    </div>

    <!-- 💡 Stay Inspired -->
    <div class="ai-card" id="aiInspireCard">
      <div class="ai-icon">💡</div>
      <div class="ai-content">
        <strong>Stay Inspired</strong>
        <p id="aiInspireText" class="ai-desc"><?php echo htmlspecialchars($ai_inspiration); ?></p>
      </div>
    </div>

  </div>
</section>


     <!-- Recommended Courses -->
    <section class="courses">
      <h3 style="margin-top:18px">Recommended For You</h3>
      <div class="courses-grid" role="list">
        <?php if (!empty($recommended_courses)): ?>
          <?php foreach ($recommended_courses as $course): ?>
            <div class="course-card" role="listitem">
              <div class="cc-icon">
                <?php 
                  // Get icon based on course level (same as courses.php)
                  $level_icons = [
                    'Beginner' => '🟢',
                    'Intermediate' => '🟡', 
                    'Advanced' => '🔴',
                    'Expert' => '🟣'
                  ];
                  $icon = $level_icons[$course['level']] ?? '📚';
                  echo $icon;
                ?>
              </div>
              <div class="cc-content">
                <strong><?php echo htmlspecialchars($course['title']); ?></strong>
                <div class="interest">
                  Level: <?php echo htmlspecialchars($course['level']); ?> • 
                  Popularity: <?php echo htmlspecialchars($course['popularity']); ?>
                </div>
                <div class="interest" style="margin-top:4px;font-size:11px">
                  <?php echo htmlspecialchars(substr($course['description'], 0, 80)); ?>
                  <?php echo strlen($course['description']) > 80 ? '...' : ''; ?>
                </div>
                <div style="margin-top:12px">
                  <!-- Use the same form approach as courses.php -->
                  <form method="POST" action="../user_dashboard_lesson/user_dashboard_lessons.php" style="display: inline;">
                    <input type="hidden" name="course_id" value="<?php echo htmlspecialchars($course['course_id']); ?>">
                    <button type="submit" class="icon-btn" style="background:var(--lilac-1);color:#fff;padding:6px 10px;border-radius:8px">
                      Start Learning
                    </button>
                  </form>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="course-card">
            <div class="cc-icon">📚</div>
            <div class="cc-content">
              <strong>No recommendations available</strong>
              <div class="interest">Complete your profile to get personalized course suggestions</div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </section>

        <!-- Leaderboard -->
    <section class="leaderboard">
      <h3 style="margin-top:22px">Top Learners</h3>
      <div style="margin-top:12px">
        <?php if (!empty($leaderboard_data)): ?>
          <?php foreach ($leaderboard_data as $index => $user): ?>
            <div class="leader-row">
              <div style="display:flex;gap:12px;align-items:center">
                <div class="rank-badge 
                  <?php echo $user['rank'] == 1 ? 'rank1' : 
                         ($user['rank'] == 2 ? 'rank2' : 
                         ($user['rank'] == 3 ? 'rank3' : 'rank-other')); ?>">
                  <?php echo $user['rank']; ?>
                </div>
                <div>
                  <strong><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></strong>
                  <div style="font-size:12px;color:var(--muted)">
                    <?php 
                      echo htmlspecialchars($user['age_group'] ?? 'Not specified');
                      if ($user['xp'] > 0) {
                        echo ' • ' . number_format($user['xp']) . ' XP';
                      }
                    ?>
                  </div>
                </div>
              </div>
              <div style="font-weight:700;color:var(--lilac-1)">
                <?php echo number_format($user['xp']); ?> XP
                <?php if ($user['rank'] <= 3): ?>
                  <?php 
                    $trophies = ['🏆', '🥈', '🥉'];
                    echo $trophies[$user['rank'] - 1];
                  ?>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="leader-row">
            <div style="display:flex;gap:12px;align-items:center">
              <div class="rank-badge rank-other">1</div>
              <div>
                <strong>No leaderboard data available</strong>
                <div style="font-size:12px;color:var(--muted)">Complete some lessons to appear here!</div>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </main>

 <!-- Chatbot -->
<div class="chatbot">
  <button class="chatbot-btn" id="chatBtn"><i class="fas fa-robot"></i></button>



  <div class="chat-window" id="chatWindow">
    <div class="chat-header">💬 LingAI — Your Smart Tutor</div>
    <p style="font-size:13px;color:#d0b3ff;margin-bottom:8px;">Hello! Ask anything about English learning 🌟</p>
    <div style="font-size:12px;color:#c8b9e6;margin-bottom:8px;">Try these:</div>
    <div class="chat-example">“Give me 3 idioms for confidence.”</div>
    <div class="chat-example">“Correct this: I goes to school.”</div>
    <div class="chat-example">“Explain present perfect in one line.”</div>
    <!-- ADD THIS -->
    <div id="chatBody"
         style="height:220px; overflow-y:auto; margin-top:10px;
               padding-right:6px; display:flex; flex-direction:column;">
    </div>
    <div style="margin-top:14px;display:flex;gap:8px;">
      <input id="chatInput" placeholder="Type your question..." style="flex:1;padding:8px;border-radius:8px;border:none;background:rgba(255,255,255,0.08);color:#fff;margin-top:30px">
      <button  type = "button" id="chatSend" style="padding:8px 14px;border-radius:8px;background:#b57aff;color:#fff;border:0;font-weight:600;margin-top:30px">Send</button>
    </div>
  </div>
</div>
<script>
    const courseSplitData = <?php echo $courseSplitJson; ?>;
      const courseProgress = <?php echo $courseProgressJson; ?>;
</script>


  <script>
  console.log("JS Loaded ✓");
  console.log("Calling update_theme.php...");

document.getElementById("chatSend").setAttribute("type", "button");

  console.log('AI Panel:', document.querySelector('.ai-writing-panel'));
console.log('AI Cards:', document.querySelectorAll('.ai-card').length);
    // --------- POPUPS ----------
    const notifBtn=document.getElementById('notifBtn');
    const notifPop=document.getElementById('notifPop');
    const msgPop=document.getElementById('msgPop');
    const profilePic=document.getElementById('profilePic');
    const profileMenu=document.getElementById('profileMenu');

    notifBtn.onclick=()=>{ notifPop.classList.toggle('show'); msgPop.classList.remove('show'); profileMenu.classList.remove('show'); }
    profilePic.onclick=()=>{ profileMenu.classList.toggle('show'); notifPop.classList.remove('show'); msgPop.classList.remove('show'); }

    // close popups on outside click
    document.addEventListener('click', (e) => {
      if (!notifBtn.contains(e.target) && !notifPop.contains(e.target)) notifPop.classList.remove('show');
      if (!profilePic.contains(e.target) && !profileMenu.contains(e.target)) profileMenu.classList.remove('show');
    });

    // --------- THEME ----------
    const themeBtn=document.getElementById('themeBtn');

// Function to save theme to database
async function saveThemeToDatabase(themeId) {
    try {
        console.log('Saving theme - Theme ID:', themeId, 'User ID:', <?php echo $user_id; ?>);
        
        const formData = new FormData();
        formData.append('theme_id', themeId);
        formData.append('user_id', <?php echo $user_id; ?>);

        // Log what's being sent
        for (let [key, value] of formData.entries()) {
            console.log('FormData:', key, value);
        }

        const response = await fetch('../user_dashboard/update_theme.php', {
            method: 'POST',
            body: formData
        });

        console.log('Response status:', response.status);
        const result = await response.json();
        console.log('Server response:', result);
        
        if (!result.success) {
            console.error('Failed to save theme:', result.message);
        } else {
            console.log('Theme saved successfully!');
        }
    } catch (error) {
        console.error('Error saving theme:', error);
    }
}

// Initialize theme from localStorage or default to light
const currentTheme = localStorage.getItem('lingo_theme') || 'light';
if (currentTheme === 'dark') {
    document.body.classList.add('dark');
}

// Theme button click handler
themeBtn.onclick = async () => {
    const isDark = document.body.classList.toggle('dark');
    const theme = isDark ? 'dark' : 'light';
    const themeId = isDark ? 2 : 1;
    
    // Save to localStorage
    localStorage.setItem('lingo_theme', theme);
    
    // Save to database
    await saveThemeToDatabase(themeId);
    
    // Show notification
    showNotification(`Theme changed to ${theme} mode`);
};
   

    // --------- SETTINGS PANEL ----------
    const settingsBtn=document.getElementById('settingsBtn');
    const settingsPanel=document.getElementById('settingsPanel');
    const settingsClose=document.getElementById('settingsClose');
    settingsBtn.onclick=()=>settingsPanel.classList.toggle('open');
    settingsClose.onclick=()=>settingsPanel.classList.remove('open');

    // --------- SUBMENUS ----------
    const flashToggle=document.getElementById('flashToggle');
    const vocabToggle=document.getElementById('vocabToggle');
    const writingToggle = document.getElementById('writingToggle'); // FIXED TYPO: was 'writintToggle'
    flashToggle.onclick=(e)=>{ e.preventDefault(); document.getElementById('flashSub').classList.toggle('show'); flashToggle.querySelector('i.fa-chevron-right').classList.toggle('fa-rotate-90'); }
    vocabToggle.onclick=(e)=>{ e.preventDefault(); document.getElementById('vocabSub').classList.toggle('show'); vocabToggle.querySelector('i.fa-chevron-right').classList.toggle('fa-rotate-90'); }
    writingToggle.onclick=(e)=>{ e.preventDefault(); document.getElementById('writingSub').classList.toggle('show'); writingToggle.querySelector('i.fa-chevron-right').classList.toggle('fa-rotate-90'); }

 // Chatbot
const chatBtn=document.getElementById('chatBtn');
const chatWindow=document.getElementById('chatWindow');
chatBtn.onclick=()=>chatWindow.classList.toggle('show');

// Click on example → fill input + show send button
document.querySelectorAll(".chat-example").forEach(ex => {
  ex.addEventListener("click", () => {
    document.getElementById("chatInput").value = ex.innerText;
  });
});

// Hide examples when a message is sent
function hideExamples() {
  document.querySelectorAll(".chat-example").forEach(ex => ex.style.display = "none");
}

document.getElementById("chatSend").addEventListener("click", async function () {
    const input = document.getElementById("chatInput");
    const text = input.value.trim();
    if (!text) return;

    hideExamples();   // 🟣 hide prompts on first message

    input.value = "";
    let body = document.getElementById("chatBody");

    body.innerHTML += `<div class="msg-user">${text}</div>`;
    body.scrollTop = body.scrollHeight;

    let loading = document.createElement("div");
    loading.innerHTML = `<div class='loading-orbs'><div></div><div></div><div></div></div>`;
    body.appendChild(loading);

    try {
        let res = await fetch("http://127.0.0.1:5001/ai_tutor", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                profile_id: <?php echo $profile_id; ?>,
                lesson_id: null,
                message: text
            })
        });

        let data = await res.json();
        loading.remove();

        body.innerHTML += `<div class="msg-ai">${data.reply}</div>`;
        body.scrollTop = body.scrollHeight;

    } catch (err) {
        loading.remove();
        body.innerHTML += `<div class="msg-ai" style="color:red;">AI server error.</div>`;
    }
});


   // --------- Mini line charts with REAL DATA AND DYNAMIC DATES ----------
function mini(id, data, color, dateLabels) {
    new Chart(document.getElementById(id), {
        type: 'line',
        data: {
            labels: dateLabels,
            datasets: [{
                data: data,
                borderColor: color,
                backgroundColor: color + '20',
                fill: true,
                tension: 0.4,
                pointRadius: 4,
                pointBackgroundColor: color,
                pointBorderColor: '#fff',
                pointBorderWidth: 2
            }]
        },
        options: {
            plugins: {
                legend: { display: false },
                tooltip: { 
                    enabled: true,
                    callbacks: {
                        label: function(context) {
                            return `${context.parsed.y} ${getChartLabel(id)}`;
                        },
                        title: function(tooltipItems) {
                            // Show actual date in tooltip
                            const today = new Date();
                            const tooltipDate = new Date();
                            tooltipDate.setDate(today.getDate() - (6 - tooltipItems[0].dataIndex));
                            return tooltipDate.toLocaleDateString('en-US', { 
                                weekday: 'long', 
                                year: 'numeric', 
                                month: 'short', 
                                day: 'numeric' 
                            });
                        }
                    }
                }
            },
            scales: {
                x: { 
                    display: true,
                    grid: { display: false },
                    ticks: {
                        maxRotation: 0,
                        callback: function(value, index, values) {
                            // Show abbreviated labels for small charts
                            const label = this.getLabelForValue(value);
                            return label.substring(0, 3); // Mon, Tue, etc.
                        }
                    }
                },
                y: { 
                    display: false,
                    beginAtZero: true,
                    grid: { display: false }
                }
            },
            elements: {
                line: {
                    borderWidth: 3
                }
            }
        }
    });
}

// Helper function to get appropriate labels
function getChartLabel(chartId) {
    const labels = {
        'mini1': 'points',
        'mini2': 'lessons',
        'mini3': 'days',
        'mini4': 'badges'
    };
    return labels[chartId] || '';
}

// Initialize charts with REAL data and DYNAMIC dates
document.addEventListener('DOMContentLoaded', function() {
    // Use PHP data passed to JavaScript
    const dateLabels = <?php echo $dateLabelsJson; ?>;
    const scoreData = <?php echo $scoreDataJson; ?>; // Real score data from PHP
    const lessonsData = <?php echo $lessonsDataJson; ?>;
    const badgesData = <?php echo $badgesDataJson; ?>;
    const streakData = <?php echo $streakDataJson; ?>;
    
    // Create charts with dynamic dates and REAL data
    mini('mini1', scoreData, '#ffd26f', dateLabels); // Score - REAL DATA
    mini('mini2', lessonsData, '#a2d2ff', dateLabels); // Lessons - REAL DATA
    mini('mini3', streakData, '#b6e3a8', dateLabels); // Streak - REAL DATA  
    mini('mini4', badgesData, '#ffc4d6', dateLabels); // Badges - REAL DATA
    
    console.log('Charts initialized with dates:', dateLabels);
    console.log('Score data:', scoreData);
    console.log('Lessons data:', lessonsData);
    console.log('Badges data:', badgesData);
    console.log('Streak data:', streakData);
});
// --------- MAIN CHARTS ----------
document.addEventListener('DOMContentLoaded', function() {
    const dateLabels = <?php echo $dateLabelsJson; ?>;
    
    // ----------------------
// WEEKLY ACTIVITY LINE CHART (REAL DATA + AFK RULE)
// ----------------------
const weeklyLabels = <?php echo $weeklyLabelsJson; ?>;
const weeklyMinutes = <?php echo $weeklyMinutesJson; ?>;

new Chart(document.getElementById('lineChart'), {
    type: 'line',
    data: {
        labels: weeklyLabels,
        datasets: [{
            label: 'Minutes',
            data: weeklyMinutes,
            borderColor: '#9b59b6',
            backgroundColor: 'rgba(155,89,182,0.2)',
            pointBackgroundColor: '#9b59b6',
            pointBorderColor: '#fff',
            pointBorderWidth: 2,
            fill: true,
            tension: 0.35
        }]
    },
    options: {
        plugins: {
            tooltip: {
                mode: 'index',
                intersect: false
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    stepSize: 10
                }
            }
        }
    }
});

    // ----------------------
    // DYNAMIC COURSE SPLIT PIE CHART
    // ----------------------
    const pieLabels = courseSplitData.map(row => row.title);
    const pieValues = courseSplitData.map(row => row.lesson_count);

    new Chart(document.getElementById('pieChart'), {
        type: 'doughnut',
        data: {
            labels: pieLabels,
            datasets: [{
                data: pieValues,
                backgroundColor: [
                    '#6a4c93',
                    '#9b59b6',
                    '#ffd26f',
                    '#a2d2ff'
                ]
            }]
        },
        options: {
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });

    // ----------------------
    // DYNAMIC TOP 5 COURSE PROGRESS BAR CHART
    // ----------------------
    const barLabels = courseProgress.map(row => row.title);
    const barValues = courseProgress.map(row => row.percentage);

    new Chart(document.getElementById('barChart'), {
        type: 'bar',
        data: {
            labels: barLabels,
            datasets: [{
                data: barValues,
                backgroundColor: [
                    '#9b59b6',
                    '#6a4c93',
                    '#ffd26f',
                    '#a2d2ff',
                    '#b6e3a8'
                ]
            }]
        },
        options: {
            indexAxis: 'y',
            plugins: {
                tooltip: { enabled: true },
                legend: { display: false }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    max: 100,
                    ticks: { stepSize: 20 }
                }
            }
        }
    });
});
    // --------- Entrance animations (staggered) ----------
window.addEventListener('DOMContentLoaded', () => {
    // Wait a bit for charts to initialize
    setTimeout(() => {
        // stagger stat cards
        document.querySelectorAll('.card').forEach((el, i) => {
            setTimeout(() => el.classList.add('enter'), 120 + i * 90);
        });
        // charts
        document.querySelectorAll('.chart-box').forEach((el, i) => {
            setTimeout(() => el.classList.add('enter'), 300 + i * 140);
        });
        // course cards
        document.querySelectorAll('.course-card').forEach((el, i) => {
            setTimeout(() => el.classList.add('enter'), 520 + i * 120);
        });
        // leaderboard rows
        document.querySelectorAll('.leader-row').forEach((el, i) => {
            setTimeout(() => el.classList.add('enter'), 760 + i * 120);
        });
    }, 100);
});

    // accessibility: keyboard toggles for sidebar submenus
    flashToggle.addEventListener('keydown', e => { if(e.key==='Enter') flashToggle.click() });
    vocabToggle.addEventListener('keydown', e => { if(e.key==='Enter') vocabToggle.click() });

    // -------- SETTINGS PANEL BEHAVIOR --------

// -------- SETTINGS PANEL BEHAVIOR --------

// profile photo upload
const editPhotoBtn = document.getElementById('editPhotoBtn');
const photoInput = document.getElementById('photoInput');
const profileImg = document.getElementById('settingsProfilePic');
editPhotoBtn.addEventListener('click', () => photoInput.click());
// Profile photo upload with database saving
photoInput.addEventListener('change', async (e) => {
  const file = e.target.files[0];
  if (!file) return;

  // Validate file type
  if (!file.type.startsWith('image/')) {
    alert('Please select an image file');
    return;
  }

  // Validate file size (max 2MB)
  if (file.size > 2 * 1024 * 1024) {
    alert('Image size should be less than 2MB');
    return;
  }

  const formData = new FormData();
  formData.append('profile_picture', file);
  formData.append('user_id', <?php echo $user_id; ?>);

  try {
    const response = await fetch('update_profile_picture.php', {
      method: 'POST',
      body: formData
    });

    const result = await response.json();

    if (result.success) {
      profileImg.src = result.new_image_url;
      showNotification('Profile picture updated successfully!');
      
      // Update header profile picture too
      const headerProfilePic = document.getElementById('profilePic');
      if (headerProfilePic) {
        headerProfilePic.src = result.new_image_url;
      }
    } else {
      alert('Error updating profile picture: ' + result.message);
    }
  } catch (error) {
    alert('Error uploading image: ' + error.message);
  }
});

// Editable fields toggle with database saving
document.querySelectorAll('.edit-btn').forEach(btn => {
  btn.addEventListener('click', async () => {
    const fieldId = btn.getAttribute('data-field');
    const field = document.getElementById(fieldId);
    const icon = btn.querySelector('i');

    if (field.disabled) {
      // Enable editing mode
      field.disabled = false;
      field.focus();
      icon.classList.replace('fa-pen', 'fa-save');
      field.style.borderBottom = '2px solid var(--lilac-2)';
    } else {
      // Save mode - send data to server
      field.disabled = true;
      icon.classList.replace('fa-save', 'fa-pen');
      field.style.borderBottom = 'none';
      
      try {
        await saveFieldToDatabase(fieldId, field.value);
        showNotification(`Successfully updated ${getFieldName(fieldId)}!`);
        
        // Update the displayed name if first/last name changed
        if (fieldId === 'firstName' || fieldId === 'lastName') {
          updateDisplayName();
        }
      } catch (error) {
        alert(`Error saving ${getFieldName(fieldId)}: ${error.message}`);
        // Revert icon if save fails
        icon.classList.replace('fa-pen', 'fa-save');
        field.disabled = false;
      }
    }
  });
});

// Function to get field name for display
function getFieldName(fieldId) {
  const fieldNames = {
    'firstName': 'First Name',
    'lastName': 'Last Name', 
    'emailField': 'Email',
    'passwordField': 'Password'
  };
  return fieldNames[fieldId] || fieldId;
}

// Function to update displayed name in settings panel
function updateDisplayName() {
  const firstName = document.getElementById('firstName').value;
  const lastName = document.getElementById('lastName').value;
  const fullNameElement = document.getElementById('userFullName');
  
  if (fullNameElement) {
    fullNameElement.textContent = `${firstName} ${lastName}`;
  }
}

// Function to save field data to database
async function saveFieldToDatabase(fieldName, fieldValue) {
  const formData = new FormData();
  formData.append('field', fieldName);
  formData.append('value', fieldValue);
  formData.append('user_id', <?php echo $user_id; ?>);

  const response = await fetch('update_profile.php', {
    method: 'POST',
    body: formData
  });

  if (!response.ok) {
    throw new Error('Network response was not ok');
  }

  const result = await response.json();
  
  if (!result.success) {
    throw new Error(result.message || 'Failed to update profile');
  }
  
  return result;
}

// Function to show notification
function showNotification(message) {
  // Create a temporary notification
  const notification = document.createElement('div');
  notification.style.cssText = `
    position: fixed;
    top: 20px;
    right: 20px;
    background: var(--lilac-1);
    color: white;
    padding: 12px 20px;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    z-index: 1000;
    font-weight: 500;
  `;
  notification.textContent = message;
  
  document.body.appendChild(notification);
  
  // Remove after 3 seconds
  setTimeout(() => {
    notification.remove();
  }, 3000);
}

/* ---------- AI Panel animation & interactions ---------- */
window.addEventListener('DOMContentLoaded', ()=> {
  setTimeout(() => {
    const aiPanel = document.querySelector('.ai-writing-panel');
    const aiCards = document.querySelectorAll('.ai-card');
    if (aiPanel) aiPanel.classList.add('enter');
    aiCards.forEach((card, i) => {
      setTimeout(() => card.classList.add('enter'), 100 + i * 120);
    });
  }, 900);
});


/* ---------- Bind Start Challenge Button ---------- */
document.getElementById('startChallengeBtn')?.addEventListener('click', () => {
    const challengeText = document.getElementById("aiChallengeText")?.textContent.trim();

    if (!challengeText) {
        alert("No challenge available.");
        return;
    }

    const type = classifyChallenge(challengeText);
    const url = getChallengeURL(type);

    console.log("Challenge Type:", type, " → Redirect:", url);

    window.location.href = url;
});

// Clicking suggestion card could open writing editor — optional
document.getElementById('aiPromptCard')?.addEventListener('click', () => {
    // e.g. open writing editor or speed review
    // window.location.href = '/writing_editor.php';
});

// Small utility: refresh AI messages without full reload (OPTIONAL)
async function refreshAICards() {
    try {
        const resp = await fetch('refresh_ai_cards.php', { method: 'POST' }); // optional endpoint if you create it
        if (!resp.ok) return;
        const json = await resp.json();
        if (json.suggestion) document.getElementById('aiPromptText').textContent = json.suggestion;
        if (json.challenge) document.getElementById('aiChallengeText').textContent = json.challenge;
        if (json.inspiration) document.getElementById('aiInspireText').textContent = json.inspiration;
    } catch (e) {
        console.warn('AI refresh failed', e);
    }
}

/* ---------- CLASSIFIER: Detect Challenge Type from AI Message ---------- */
function classifyChallenge(message) {
    const msg = message.toLowerCase();

    if (msg.includes("lesson") || msg.includes("course") || msg.includes("grammar") || msg.includes("quiz"))
        return "course";

    if (msg.includes("vocabulary") || msg.includes("word"))
        return "vocab";

    if (msg.includes("flash") || msg.includes("flashcard")  || msg.includes("flashcards"))
        return "flash";

    if (msg.includes("write") || msg.includes("writing") || msg.includes("paragraph") || msg.includes("essay"))
        return "writing";

    return "default";
}

/* ---------- Resolve Route Based on Classification ---------- */
function getChallengeURL(type) {
    switch (type) {
        case "course":
            return "../courses/courses.php";
        case "vocab":
            return "../user_dashboard_words/user_dashboard_words.php";
        case "flash":
            return "../user_flashcards/user_flashcard.php";
        case "writing":
            return "../writing_evaluation/writing_evaluation.php";
        default:
            return "../user_dashboard/user-dashboard.php";
    }
}


// Simple redirect function (alternative to form submission)
function enrollCourse(courseId) {
    // Show loading notification
    showNotification('Taking you to the course...');
    
    // Redirect after a short delay
    setTimeout(() => {
        // Create a temporary form to submit via POST (same as courses.php)
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '../user_dashboard_lesson/user_dashboard_lessons.php';
        
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'course_id';
        input.value = courseId;
        
        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
    }, 500);
}


// Add hover effects for AI cards
document.querySelectorAll('.ai-card').forEach(card => {
  card.addEventListener('mouseenter', function() {
    this.style.transform = 'translateY(-8px) scale(1.03)';
  });
  
  card.addEventListener('mouseleave', function() {
    if (!this.classList.contains('enter')) return;
    this.style.transform = 'translateY(0) scale(1)';
  });
});

// Toggle popup
document.getElementById("notifBtn").addEventListener("click", () => {
    let p = document.getElementById("notifPopup");
    p.style.display = p.style.display === "flex" ? "none" : "flex";
});

// Mark as read
$(document).on("click", ".mark-read-btn", function (e) {
    e.stopPropagation();

    let nid = $(this).data("id");
    let item = $(this).closest(".notif-item");
    let btn = $(this);

   $.post("", { mark_read: nid }, function (res) {
    if (res.trim() === "ok") {
        item.removeClass("unread").addClass("read");
        btn.text("Seen");
        btn.prop("disabled", true).css("opacity","0.6");
    }
});

});

  </script>

</body>
</html>
