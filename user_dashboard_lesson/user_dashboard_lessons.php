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
    die("Connection failed: " . mysqli_connect_error());
}

// Fetch profile
$sql = "SELECT profile_picture FROM user WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $_SESSION['profile_picture'] = $row['profile_picture'];
}

// Theme
$sql = "SELECT theme_id FROM sets WHERE user_id = ? ORDER BY set_on DESC LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$theme_id = 1;
if ($row = $result->fetch_assoc()) {
    $theme_id = $row['theme_id'];
}

// Handle posted course_id
$course_id = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['course_id'])) {
    $course_id = $_POST['course_id'];
    log_user_activity($user_id, 'course enroll');
}

function log_user_activity($user_id, $action_type) {
    $conn2 = mysqli_connect("localhost", "root", "", "lingoland_db");
    if (!$conn2) return;
    $action_time = date("Y-m-d H:i:s");
    $sql = "INSERT INTO activity_log (user_id, action_type, action_time) VALUES (?, ?, ?)";
    $stmt = $conn2->prepare($sql);
    $stmt->bind_param("iss", $user_id, $action_type, $action_time);
    $stmt->execute();
    $stmt->close();
    mysqli_close($conn2);
}

// Filters
$search_query = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search'])) {
    $search_query = trim($_POST['search']);
}

$sort_by = $_POST['sort_by'] ?? '';
$filter_level = $_POST['filter_level'] ?? 'all';
if (isset($_POST['reset_filters'])) {
    $sort_by = '';
    $filter_level = 'all';
}

// Build lesson query
$sql = "SELECT lesson.lesson_id, lesson.title, lesson.content, lesson.difficulty, course.title AS course_title
        FROM lesson
        JOIN course ON lesson.course_id = course.course_id
        WHERE lesson.course_id='" . mysqli_real_escape_string($conn, $course_id) . "'";

if ($search_query != '') {
    $sq = mysqli_real_escape_string($conn, $search_query);
    $sql .= " AND (lesson.title LIKE '%$sq%' OR lesson.lesson_id LIKE '%$sq%')";
}

if ($filter_level !== 'all') {
    $sql .= " AND lesson.difficulty = '" . mysqli_real_escape_string($conn, $filter_level) . "'";
}

switch ($sort_by) {
    case 'level_asc':
        $sql .= " ORDER BY FIELD(lesson.difficulty, 'easy','medium','hard')";
        break;
    case 'level_desc':
        $sql .= " ORDER BY FIELD(lesson.difficulty, 'hard','medium','easy')";
        break;
    case 'az':
        $sql .= " ORDER BY lesson.title ASC";
        break;
    case 'za':
        $sql .= " ORDER BY lesson.title DESC";
        break;
    default:
        $sql .= " ORDER BY lesson.lesson_id ASC";
        break;
}

$result = mysqli_query($conn, $sql);
if (!$result) {
    die('Error in query execution: ' . mysqli_error($conn));
}

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


?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lingoland - Lessons</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
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
  font-family: 'Poppins', system-ui, sans-serif;
  background: var(--bg-light);
  color: var(--text-light);
  transition: background .28s ease, color .28s ease;
  min-height:100vh;
}
body.dark { background: var(--bg-dark); color: var(--text-dark); }
/* Sidebar */
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
.logo-circle{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-weight:800;background:linear-gradient(135deg,rgba(255,255,255,0.12),rgba(255,255,255,0.06));box-shadow: 0 8px 22px rgba(0,0,0,0.12);font-size:20px;}
.sidebar h3{margin:0;font-size:18px;}
.menu a{
  display:flex;align-items:center; gap:12px; 
  padding:10px 12px;border-radius:10px;color:#fff;text-decoration:none;
  transition:background .2s, transform .15s; font-weight:600;
}
.menu a:hover{background: rgba(255,255,255,0.12); transform: translateX(6px)}
.menu a.active { 
      background:var(--active-bg);
      color:var(--active-text);
      font-weight:700;
      transform:translateX(6px);
    } 
.menu .sub {display:none; margin-left:18px; flex-direction:column; gap:6px; margin-top:6px}
.menu .sub a{font-weight:500; font-size:14px; padding-left:18px; color:rgba(255,255,255,0.9)}
.sub.show { display:flex; animation:fade .28s ease; }
@keyframes fade { from { opacity: 0; transform: translateY(-6px) } to { opacity:1; transform: translateY(0) } }
.header {
  position: fixed; left:240px; right:0; top:0; height:64px;
  display:flex; align-items:center; justify-content:space-between;
  padding:10px 20px; background: var(--card-light); border-bottom:1px solid rgba(0,0,0,0.04);
  z-index: 40;
}
body.dark .header { background: var(--card-dark); border-bottom:1px solid rgba(255,255,255,0.04) }
.header .left {display:flex;align-items:center; gap:14px}
.header .right { display:flex; align-items:center; gap:12px; position:relative }
.header .info strong{display:block}
.icon-btn{background:transparent;border:0;padding:8px;border-radius:8px;cursor:pointer;font-size:16px;}
.badge {position: absolute; top:2px; right:2px;background:#ff3b3b; color:#fff;font-size:10px; font-weight:600; padding:2px 5px; border-radius:50%;}

main{ margin-left:240px; padding:94px 28px 48px 28px; min-height:100vh; }
h1{ margin:0; font-size:22px; font-weight:700; }
.lessons-grid { display:flex; gap:14px; margin-top:18px; flex-wrap:wrap }
.lesson-card{ width: 240px; min-height: 100px; padding: 16px;
  border-radius: 18px; background: linear-gradient(135deg, #faf5ff, #f5f0ff);
  border: 2px solid rgba(155, 89, 182, 0.25);
  transition: transform .28s ease, box-shadow .28s ease, opacity .36s ease;
  position: relative; overflow: hidden; opacity: 0;
  transform: translateY(12px) scale(.995);}
.lesson-card.enter{opacity: 1; transform: translateY(0) scale(1);}
body.dark .lesson-card{background: linear-gradient(135deg,#20132d,#3a2951); border: 2px solid rgba(255,255,255,0.08);}
.lesson-card:hover{transform: translateY(-8px) scale(1.03); box-shadow: 0 20px 40px rgba(106,76,147,0.25); border-color: #9b59b6;}
.lc-icon{width: 46px; height: 46px; border-radius: 12px; display: flex; align-items: center; justify-content: center; background: var(--gradient); color: #fff; position: absolute; left: 12px; top: 12px; font-size: 20px;}
.lc-content{margin-left:56px}
.lc-content .level{font-size:12px; color:#9b59b6; margin-top:6px; font-weight:bold;}
.search-bar{display:flex;justify-content:center;margin:20px 0}
.search-bar form{display:flex;align-items:center;gap:10px}
.search-bar input[type=text]{padding:10px 14px;border:2px solid #6c63ff;border-radius:8px;outline:none;width:300px;font-size:14px}
.search-btn{background:var(--gradient);color:#fff;border:none;padding:10px 18px;border-radius:8px;font-weight:500;cursor:pointer}
.enroll-btn{margin-top:8px;padding:6px 12px;border-radius:15px;background:var(--gradient);color:#fff;border:none;font-weight:600;cursor:pointer;transition:.3s}
.enroll-btn:hover{background:linear-gradient(135deg,#9b59b6,#6a4c93);transform:scale(1.05)}
.chatbot{position:fixed;right:30px;bottom:22px;z-index:120;display:flex;flex-direction:column;align-items:flex-end;gap:8px}
.chatbot-btn{width:60px;height:60px;border-radius:50%;border:0;background:var(--gradient);color:white;font-size:22px;cursor:pointer;box-shadow:0 12px 36px rgba(0,0,0,0.18)}
.chat-window{width:360px;min-height:360px;background:radial-gradient(circle at top left,#2b163e,#1b102c 80%);color:#f4f0ff;border-radius:16px;padding:16px;box-shadow:0 25px 50px rgba(0,0,0,0.25);display:none;opacity:0;transform:translateY(10px);transition:.4s}
.chat-window.show{display:block;opacity:1;transform:translateY(0)}
.settings-panel{position:fixed;right:-460px;top:0;width:420px;height:100vh;background:var(--card-light);box-shadow:-16px 0 40px rgba(0,0,0,0.14);padding:18px;transition:right .32s ease;z-index:90}
.settings-panel.open{right:0}
.settings-close{position:absolute;top:10px;right:14px;background:none;border:none;color:var(--muted);font-size:20px;cursor:pointer}
body.dark .settings-panel{background:var(--card-dark)}

/* Settings panel */
.settings-panel{position: fixed; right:-460px; top:0; width:420px; height:100vh; background:var(--card-light); box-shadow:-16px 0 40px rgba(0,0,0,0.14); padding:18px; transition:right .32s ease; z-index:90;}
.settings-panel.open{ right:0; }
.settings-close{ position:absolute; top:10px; right:14px; background:none; border:none; color:var(--muted); font-size:20px; cursor:pointer; }
body.dark .settings-panel{ background:var(--card-dark); }

#settingsBtn { font-size:18px; color:var(--muted); }
    #settingsBtn { animation: rotating 8s linear infinite; }
    @keyframes rotating { 0%{ transform:rotate(0deg) } 100%{ transform:rotate(360deg) } }

/* Settings input hover */
.settings-panel input[type="text"],
.settings-panel input[type="email"],
.settings-panel input[type="password"] {
  width: 100%; padding: 10px 12px; border-radius: 10px;
  border: 2px solid transparent;
  background: linear-gradient(#fff, #fff) padding-box,
              linear-gradient(90deg, #6a4c93, #9b59b6, #ffd26f) border-box;
  font-family: 'Poppins', sans-serif;
  font-size: 14px; color: var(--text-light);
  transition: all 0.3s ease;
}
.settings-panel input:hover,
.settings-panel input:focus {
  outline: none;
  transform: scale(1.02);
  box-shadow: 0 0 12px rgba(155, 89, 182, 0.5);
  background: linear-gradient(#fff, #fff) padding-box,
              linear-gradient(135deg, #9b59b6, #ffd26f) border-box;
}



/* Responsive */
@media (max-width: 980px){
  .sidebar{ width:72px; padding:14px }
  .header{ left:72px }
  main{ margin-left:72px; padding:86px 12px }
  .courses-grid{ justify-content:flex-start }
}

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
    .chatbot-btn{ width:60px; height:60px; border-radius:50%; border:0; background:var(--gradient); color:white; font-size:22px; cursor:pointer; box-shadow:0 12px 36px rgba(0,0,0,0.18); margin-top:20px;}
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

    /* Filter dropdown design */
.filter-menu {
  width: 320px;
  background: var(--card-light);
  border-radius: 16px;
  box-shadow: 0 12px 28px rgba(0, 0, 0, 0.15);
  padding: 20px 18px;
  display: none;
  flex-direction: column;
  gap: 20px;
  transition: all 0.3s ease;
  border: 1px solid rgba(155, 89, 182, 0.15);
}


/* Hide the actual radio circles for a cleaner, button-only look */
.btn-check {
  display: none;
}


body.dark .filter-menu {
  background: var(--card-dark);
  border: 1px solid rgba(255, 255, 255, 0.08);
  box-shadow: 0 12px 30px rgba(181, 122, 255, 0.1);
}

/* Show on toggle */
.filter-menu.show {
  display: flex;
  animation: fadeIn 0.25s ease forwards;
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(-6px); }
  to { opacity: 1; transform: translateY(0); }
}

/* Section titles */
.filter-menu .small.text-muted {
  font-weight: 600;
  font-size: 13px;
  color: var(--lilac-1);
  border-bottom: 1px solid rgba(155, 89, 182, 0.2);
  padding-bottom: 6px;
  margin-bottom: 8px;
}

/* Option buttons (radio labels) */
.btn-outline-option {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 2px solid rgba(155, 89, 182, 0.3);
  border-radius: 10px;
  padding: 6px 14px;
  font-size: 13px;
  font-weight: 500;
  color: var(--lilac-1);
  background: transparent;
  cursor: pointer;
  transition: all 0.3s ease;
  margin: 4px;
}

body.dark .btn-outline-option {
  color: #e9eefc;
  border-color: rgba(181, 122, 255, 0.3);
}

.btn-outline-option:hover {
  background: rgba(155, 89, 182, 0.1);
  transform: translateY(-2px);
}

/* When selected */
.btn-check:checked + .btn-outline-option {
  background: linear-gradient(135deg, #b57aff, #9b59b6);
  color: white;
  border-color: transparent;
  box-shadow: 0 4px 10px rgba(155, 89, 182, 0.3);
}

/* Layout rows like “Gender” example */
.filter-menu .btn-group-vertical {
  flex-direction: row;
  flex-wrap: wrap;
  justify-content: flex-start;
  gap: 10px;
}

/* Divider line between groups */
.filter-menu .divider {
  height: 3px;
  background: rgba(155, 89, 182, 0.2);
  margin: 8px 0;
}

/* Apply + Reset buttons */
.btn-apply, .btn-reset {
  border: none;
  border-radius: 10px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  padding: 10px 16px;
  transition: all 0.3s ease;
}

.btn-apply {
  background: linear-gradient(135deg, #b57aff, #ff7ce4);
  color: #fff;
  box-shadow: 0 4px 10px rgba(181, 122, 255, 0.4);
}

.btn-apply:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 16px rgba(181, 122, 255, 0.5);
}

.btn-reset {
  background: rgba(155, 89, 182, 0.12);
  color: var(--lilac-1);
}

.btn-reset:hover {
  background: rgba(155, 89, 182, 0.25);
}

.btn-filter {
  background: var(--gradient);
  color: #fff;
  border: none;
  padding: 10px 16px;
  border-radius: 10px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.3s ease;
  box-shadow: 0 6px 14px rgba(106, 76, 147, 0.25);
}
.btn-filter:hover {
  transform: translateY(-2px);
  box-shadow: 0 10px 20px rgba(155, 89, 182, 0.3);
}

/* ----------------------------------------
   NOTIFICATION POPUP DESIGN
-------------------------------------------*/
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
<body class="<?php echo ($theme_id == 2)?'dark':''; ?>">

<!-- Sidebar -->
<div class="sidebar">
  <div class="brand"><div class="logo-circle">L</div><h3>Lingoland</h3></div>
  <div class="menu">
     <a href="../user_dashboard/user-dashboard.php"><i class="fas fa-home"></i> Home</a>
    <a href="#" class="active"><i class="fas fa-book"></i> Courses</a>
    <a id="flashToggle"><i class="fas fa-th-large"></i> Flashcards <i class="fas fa-chevron-right"></i></a>
    <div class="sub" id="flashSub">
      <a href="#">Review</a>
      <a href="#">Add New</a>
    </div>
    <a id="vocabToggle"><i class="fas fa-language"></i> Vocabulary <i class="fas fa-chevron-right"></i></a>
    <div class="sub" id="vocabSub">
      <a href="#">Study</a>
      <a href="#">Your Dictionary</a>
    </div>
    <a href="#"><i class="fas fa-pencil-alt"></i> Quiz</a>
    <a href="#"><i class="fas fa-trophy"></i> Leaderboard</a>
    <a href="#"><i class="fas fa-comments"></i> Forum</a>
    <a href="#"><i class="fas fa-award"></i> Badges</a>
     <a href="#"><i class="fas fa-certificate"></i> <span>Certificates</span></a>
    <a href="#"><i class="fas fa-robot"></i> AI Assistant</a>
    <a href="../user_dashboard/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
  </div>
</div>

<!-- Header -->
<div class="header">
  <div class="left">
    <button class="icon-btn" id="sidebarToggle"><i class="fas fa-bars"></i></button>
    <h1>Lesson Module</h1>
  </div>
  <div class="right">
    <button class="icon-btn" id="notifBtn">🔔<span class="badge" id="notifBadge">5</span></button>
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

    <button class="icon-btn" id="messageBtn">📩<span class="badge" id="msgBadge">3</span></button>
    <div id="msgPop" style="display:none;position:absolute;top:64px;right:60px;background:var(--card-light);padding:10px;border-radius:10px;">You have new messages</div>
    <button class="icon-btn" id="settingsBtn"><i class="fas fa-cog"></i></button>
    <span id="darkModeToggle" class="icon-btn">🌓</span>
    <img src="<?php echo !empty($_SESSION['profile_picture']) ? '../settings/' . $_SESSION['profile_picture'] : 'https://i.pravatar.cc/40'; ?>" style="border-radius:50%">
  </div>
</div>

<!-- SETTINGS PANEL (updated with profile editing UI) -->
<aside id="settingsPanel" class="settings-panel" aria-hidden="true">
  <button class="settings-close" id="settingsClose"><i class="fas fa-times"></i></button>

  <div class="settings-profile">
    <div class="profile-photo">
      <img id="settingsProfilePic" src="https://i.pravatar.cc/120?img=12" alt="Profile Picture">
      <button id="editPhotoBtn" class="edit-photo"><i class="fas fa-pen"></i></button>
      <input type="file" id="photoInput" accept="image/*" hidden>
    </div>
    <h3 id="userFullName">Ramisa Anjum</h3>
    <p id="userEmail">ramisa.anjum345@gmail.com</p>
  </div>

  <div class="settings-section">
    <h4>Profile</h4>
    <div class="field-group">
      <input type="text" id="firstName" value="Ramisa" disabled>
      <button class="edit-btn" data-field="firstName"><i class="fas fa-pen"></i></button>
    </div>
    <div class="field-group">
      <input type="text" id="lastName" value="Anjum" disabled>
      <button class="edit-btn" data-field="lastName"><i class="fas fa-pen"></i></button>
    </div>
  </div>

  <div class="settings-section">
    <h4>Account</h4>
    <div class="field-group">
      <input type="email" id="emailField" value="ramisa.anjum345@gmail.com" disabled>
      <button class="edit-btn" data-field="emailField"><i class="fas fa-pen"></i></button>
    </div>
    <div class="field-group">
      <input type="password" id="passwordField" value="••••••" disabled>
      <button class="edit-btn" data-field="passwordField"><i class="fas fa-pen"></i></button>
    </div>
  </div>
</aside>
<!-- Main -->
<main>
  <h1>Available Lessons</h1>
  <div class="search-bar">
    <form method="POST">
      <input type="hidden" name="course_id" value="<?php echo htmlspecialchars($course_id); ?>">
      <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Search lessons...">
      <button type="submit" class="search-btn">Search</button>
    </form>
  </div>                 
                    <div class="filter-wrapper">
                        <div class="dropdown">
                            <button class="btn btn-filter dropdown-toggle" type="button" id="sfMenu"
                                data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" >
                                Sort & Filter
                            </button>
                            <div class="dropdown-menu dropdown-menu-end p-3 filter-menu" aria-labelledby="sfMenu">
                                <form method="POST" action="courses.php" class="filter-form">
                                 
                                    <div class="mb-2 small text-muted">Sort by</div>
                                    <div class="btn-group-vertical w-100 mb-3" role="group" aria-label="Sort by">
                                        <input type="radio" class="btn-check" name="sort_by" id="sort_level_asc" value="level_asc" <?php echo ($sort_by==='level_asc')?'checked':''; ?>>
                                        <label class="btn btn-outline-option" for="sort_level_asc">Difficulty: Easy → Hard</label>

                                        <input type="radio" class="btn-check" name="sort_by" id="sort_level_desc" value="level_desc" <?php echo ($sort_by==='level_desc')?'checked':''; ?>>
                                        <label class="btn btn-outline-option" for="sort_level_desc">Difficulty: Hard → Easy</label>

                                        <input type="radio" class="btn-check" name="sort_by" id="sort_az" value="az" <?php echo ($sort_by==='az')?'checked':''; ?>>
                                        <label class="btn btn-outline-option" for="sort_az">A–Z (Title)</label>

                                        <input type="radio" class="btn-check" name="sort_by" id="sort_za" value="za" <?php echo ($sort_by==='za')?'checked':''; ?>>
                                        <label class="btn btn-outline-option" for="sort_za">Z–A (Title)</label>

                                        <input type="radio" class="btn-check" name="sort_by" id="sort_default" value="" <?php echo ($sort_by==='')?'checked':''; ?>>
                                        <label class="btn btn-outline-option" for="sort_default">Default</label>
                                    </div>

                                 
                                    <div class="mb-2 small text-muted">Filter by level</div>
                                    <div class="btn-group-vertical w-100 mb-3" role="group" aria-label="Filter">
                                        <input type="radio" class="btn-check" name="filter_level" id="lvl_all" value="all" <?php echo ($filter_level==='all')?'checked':''; ?>>
                                        <label class="btn btn-outline-option" for="lvl_all">All Levels</label>

                                        <input type="radio" class="btn-check" name="filter_level" id="lvl_beginner" value="beginner" <?php echo ($filter_level==='beginner')?'checked':''; ?>>
                                        <label class="btn btn-outline-option" for="lvl_beginner">Easy</label>

                                        <input type="radio" class="btn-check" name="filter_level" id="lvl_intermediate" value="intermediate" <?php echo ($filter_level==='intermediate')?'checked':''; ?>>
                                        <label class="btn btn-outline-option" for="lvl_intermediate">Moderate</label>

                                        <input type="radio" class="btn-check" name="filter_level" id="lvl_advanced" value="advanced" <?php echo ($filter_level==='advanced')?'checked':''; ?>>
                                        <label class="btn btn-outline-option" for="lvl_advanced">Hard</label>
                                    </div>

                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-apply w-100">Apply</button>
                                        <button type="submit" name="reset_filters" value="1" class="btn btn-reset w-100">Reset</button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        
                        <div class="active-pills mt-2 text-end">
                            <?php if ($sort_by): ?>
                                <span class="pill"><?php echo htmlspecialchars(str_replace('_',' ', strtoupper($sort_by))); ?></span>
                            <?php endif; ?>
                            <?php if ($filter_level !== 'all'): ?>
                                <span class="pill"><?php echo ucfirst(htmlspecialchars($filter_level)); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>


  <div class="lessons-grid" style="display:flex;flex-wrap:wrap;gap:16px;">
  <?php
  if (mysqli_num_rows($result) > 0) {
      while ($lesson = mysqli_fetch_assoc($result)) {
          echo '
          <div class="lesson-card stagger">
            <div class="lc-icon"><i class="fas fa-book-open"></i></div>
            <div class="lc-content">
              <h3>'.htmlspecialchars($lesson['title']).'</h3>
              <p class="level">Level: '.htmlspecialchars($lesson['difficulty']).'</p>
              <form method="POST" action="../lessons/lessons.php">
                <input type="hidden" name="lesson_id" value="'.htmlspecialchars($lesson['lesson_id']).'">
                <button type="submit" class="enroll-btn">Start Now</button>
              </form>
            </div>
          </div>';
      }
  } else echo "<p>No lessons found!</p>";
  ?>
  </div>
</main>

<!-- Chatbot -->
<div class="chatbot">
  <button class="chatbot-btn" id="chatBtn"><i class="fas fa-robot"></i></button>
  <div class="chat-window" id="chatWindow">
    <div class="chat-header">💬 LingAI — Your Smart Tutor</div>
    <p style="font-size:13px;color:#d0b3ff;margin-bottom:8px;">Hello! Ask anything about English learning 🌟</p>
    <div class="loading-orbs"><div></div><div></div><div></div></div>
    <div style="font-size:12px;color:#c8b9e6;margin-bottom:8px;">Try these:</div>
    <div class="chat-example">“Give me 3 idioms for confidence.”</div>
    <div class="chat-example">“Correct this: I goes to school.”</div>
    <div class="chat-example">“Explain present perfect in one line.”</div>
    <div style="margin-top:14px;display:flex;gap:8px;">
      <input id="chatInput" placeholder="Type your question..." style="flex:1;padding:8px;border-radius:8px;border:none;background:rgba(255,255,255,0.08);color:#fff;margin-top:30px">
      <button id="chatSend" style="padding:8px 14px;border-radius:8px;background:#b57aff;color:#fff;border:0;font-weight:600;margin-top:30px">Send</button>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>


<script>

// Sidebar submenu toggle
const flashToggle=document.getElementById('flashToggle');
const vocabToggle=document.getElementById('vocabToggle');
flashToggle.addEventListener('click',()=>document.getElementById('flashSub').classList.toggle('show'));
vocabToggle.addEventListener('click',()=>document.getElementById('vocabSub').classList.toggle('show'));

const notifBtn=document.getElementById('notifBtn');
const msgBtn=document.getElementById('messageBtn');
notifBtn.onclick=()=>{document.getElementById('notifPop').style.display=document.getElementById('notifPop').style.display==='block'?'none':'block';document.getElementById('msgPop').style.display='none';};
msgBtn.onclick=()=>{document.getElementById('msgPop').style.display=document.getElementById('msgPop').style.display==='block'?'none':'block';document.getElementById('notifPop').style.display='none';};

const settingsBtn=document.getElementById('settingsBtn');
const settingsPanel=document.getElementById('settingsPanel');
const settingsClose=document.getElementById('settingsClose');
settingsBtn.onclick=()=>settingsPanel.classList.add('open');
settingsClose.onclick=()=>settingsPanel.classList.remove('open');

const darkToggle=document.getElementById('darkModeToggle');
darkToggle.addEventListener('click',()=>{document.body.classList.toggle('dark');localStorage.setItem('darkMode',document.body.classList.contains('dark'));});
if(localStorage.getItem('darkMode')==='true')document.body.classList.add('dark');

const chatBtn=document.getElementById('chatBtn');
const chatWindow=document.getElementById('chatWindow');
chatBtn.onclick=()=>chatWindow.classList.toggle('show');

window.addEventListener('load',()=>{document.querySelectorAll('.lesson-card').forEach((el,i)=>{setTimeout(()=>{el.classList.add('enter');},i*120);});});

document.addEventListener("DOMContentLoaded", function () {
  const toggleBtn = document.getElementById('sfMenu');           // Sort & Filter button
  const filterMenu = document.querySelector('.dropdown .filter-menu');  // actual dropdown menu
  const filterForm = document.querySelector('.filter-form');

  if (!toggleBtn || !filterMenu) return;

  toggleBtn.addEventListener('click', function (e) {
    e.stopPropagation();
    const showing = filterMenu.classList.contains('show');
    if (showing) {
      filterMenu.classList.remove('show');
      filterMenu.parentElement.classList.remove('show');
      toggleBtn.setAttribute('aria-expanded', 'false');
    } else {
      filterMenu.classList.add('show');
      filterMenu.parentElement.classList.add('show');
      toggleBtn.setAttribute('aria-expanded', 'true');
    }
  });

  document.addEventListener('click', function (ev) {
    if (!filterMenu.contains(ev.target) && ev.target !== toggleBtn) {
      filterMenu.classList.remove('show');
      filterMenu.parentElement.classList.remove('show');
      toggleBtn.setAttribute('aria-expanded', 'false');
    }
  });

  if (filterForm) {
    filterForm.querySelectorAll('.btn-apply, .btn-reset').forEach(btn => {
      btn.addEventListener('click', function () {
        filterMenu.classList.remove('show');
        filterMenu.parentElement.classList.remove('show');
        toggleBtn.setAttribute('aria-expanded', 'false');
      });
    });
  }

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') {
      filterMenu.classList.remove('show');
      filterMenu.parentElement.classList.remove('show');
      toggleBtn.setAttribute('aria-expanded', 'false');
    }
  });
});
// -------- SETTINGS PANEL BEHAVIOR --------

// profile photo upload
const editPhotoBtn = document.getElementById('editPhotoBtn');
const photoInput = document.getElementById('photoInput');
const profileImg = document.getElementById('settingsProfilePic');
editPhotoBtn.addEventListener('click', () => photoInput.click());
photoInput.addEventListener('change', (e) => {
  const file = e.target.files[0];
  if (file) {
    const reader = new FileReader();
    reader.onload = () => {
      profileImg.src = reader.result;
    };
    reader.readAsDataURL(file);
  }
});

// editable fields toggle
document.querySelectorAll('.edit-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    const fieldId = btn.getAttribute('data-field');
    const field = document.getElementById(fieldId);
    const icon = btn.querySelector('i');

    if (field.disabled) {
      field.disabled = false;
      field.focus();
      icon.classList.replace('fa-pen', 'fa-save');
      field.style.borderBottom = '2px solid var(--lilac-2)';
    } else {
      field.disabled = true;
      icon.classList.replace('fa-save', 'fa-pen');
      field.style.borderBottom = 'none';
      alert(`Saved: ${field.value}`);
    }
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

<?php mysqli_close($conn); ?>
