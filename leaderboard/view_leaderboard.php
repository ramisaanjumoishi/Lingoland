<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../login/login_process.php"); exit(); }
$user_id = (int)$_SESSION['user_id'];

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$servername = "localhost"; $username = "root"; $password = ""; $dbname = "lingoland_db";
$conn = new mysqli($servername, $username, $password, $dbname);
$conn->set_charset('utf8mb4');

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

$profile_id = null;
$sql_profile = "SELECT profile_id FROM user_profile WHERE user_id = ?";
$stmt_p = $conn->prepare($sql_profile);
$stmt_p->bind_param("i", $user_id);
$stmt_p->execute();
$res_p = $stmt_p->get_result();
if ($row_p = $res_p->fetch_assoc()) $profile_id = $row_p['profile_id'];


$stmt = $conn->prepare("SELECT theme_id FROM `sets` WHERE user_id = ? ORDER BY set_on DESC LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$theme_id = 1;
if ($r = $stmt->get_result()->fetch_assoc()) $theme_id = (int)$r['theme_id'];
$stmt->close();


$q_age = "
  SELECT age_group 
  FROM user_profile 
  WHERE user_id = $user_id
";
$res_age = $conn->query($q_age);
$row_age = $res_age->fetch_assoc();
$current_age_group = $row_age['age_group'];


/*
  NOTE: We expand the SELECT to get user.email and user_profile.age_group.
  Then we fetch all rows into $allRows for rendering podium + table.
*/
$q = "
  SELECT l.xp, l.last_updated,
         u.user_id, u.first_name, u.last_name, u.profile_picture, u.email,
         up.age_group,
         COALESCE(b.badge_count, 0) AS badge_count
  FROM leaderboard l
  JOIN user u ON u.user_id = l.user_id
  LEFT JOIN user_profile up ON up.user_id = u.user_id
  LEFT JOIN (
      SELECT user_id, COUNT(*) AS badge_count
      FROM earned_by
      GROUP BY user_id
  ) b ON b.user_id = l.user_id
  WHERE up.age_group = '$current_age_group'
  ORDER BY l.xp DESC
";
$res = $conn->query($q);
$allRows = $res->fetch_all(MYSQLI_ASSOC);

// Recalculate rank locally based on XP (descending)
usort($allRows, function($a, $b) {
    return $b['xp'] <=> $a['xp'];
});

$rank = 1;
foreach ($allRows as &$row) {
    $row['rank'] = $rank;
    $rank++;
}
unset($row); // break reference


// Provide JSON for client-side search/sort (if JS wants it)
$rows_json = json_encode($allRows, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);

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
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Lingoland - Flashcards</title>

<!-- fonts & icons -->
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>

<style>
/* === Base theme copied/kept consistent with your main theme === */
:root {
  --lilac-1: #6a4c93;
  --lilac-2: #9b59b6;
  --bg-light: #f6f7fb;
  --bg-dark: #0f1115;
  --card-light: #ffffff;
  --card-dark: #16171b;
  --text-light: #222;
  --text-dark: #e9eefc;
  --muted: #6b6b6b;
  --gradient: linear-gradient(135deg,#6a4c93,#9b59b6);
  --active-bg: rgba(255,255,255,0.25);
   --active-text: #ffd26f;
   --accent1: #6a4c93;
  --accent2: #9b59b6;
 --accent3: #ffd26f
  --glass: rgba(255,255,255,0.6);
--shadow-lg: 0 20px 45px rgba(106, 76, 147, 0.15);
 --shadow-sm: 0 5px 15px rgba(0, 0, 0, 0.1);
}
*{box-sizing:border-box}
body{margin:0;font-family:'Poppins',system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:var(--bg-light);color:var(--text-light);min-height:100vh;transition:background .28s,color .28s}
body.dark{background:var(--bg-dark);color:var(--text-dark)}

/* Sidebar similar */
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


/* Header */
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

/* Main */
main{ margin-left:240px; padding:94px 28px 48px 28px; min-height:100vh; }
h1{ margin:0; font-size:22px; font-weight:700; margin-top:-25px; }
h2{ margin:0; font-size: 18px; font-weight:600; margin-bottom: 20px; margin-top: 20px;}

/* Course cards */
.courses-grid { display:flex; gap:14px; margin-top:18px; flex-wrap:wrap }
.course-card {
  width: 240px; min-height: 100px; padding: 16px;
  border-radius: 18px; background: linear-gradient(135deg, #faf5ff, #f5f0ff);
  border: 2px solid rgba(155, 89, 182, 0.25);
  transition: transform .28s ease, box-shadow .28s ease, opacity .36s ease;
  position: relative; overflow: hidden; opacity: 0;
  transform: translateY(12px) scale(.995);
}
.course-card.enter { opacity: 1; transform: translateY(0) scale(1); }
body.dark .course-card { background: linear-gradient(135deg,#20132d,#3a2951); border: 2px solid rgba(255,255,255,0.08); }
.course-card:hover { transform: translateY(-8px) scale(1.03); box-shadow: 0 20px 40px rgba(106,76,147,0.25); border-color: #9b59b6; }
.course-card .cc-icon { width: 46px; height: 46px; border-radius: 12px; display: flex; align-items: center; justify-content: center; background: var(--gradient); color: #fff; position: absolute; left: 12px; top: 12px; font-size: 20px; }
.course-card .interest { font-size:12px; color:var(--muted); margin-top:6px; }
.course-card .level {font-size:12px; color:#9b59b6; margin-top:6px; font-weight:bold;}
.course-card .cc-content { margin-left: 56px; }



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
/* Main content area */
main{margin-left:240px;padding:94px 28px 48px 28px;min-height:100vh}

/* Flashcard view area (centered) */
.flashcard-wrapper { display:flex; gap:28px; align-items:flex-start; justify-content:center; flex-wrap:wrap; margin-top:12px; }
.flashcard-column { width:720px; max-width:calc(100% - 40px); }

/* The big card style (matching mobile gradient) */
.flashcard {
  height: 420px;
  border-radius: 22px;
  background: linear-gradient(135deg,#9b59b6 0%, #b57aff 45%, #6a4c93 100%);
  box-shadow: 0 20px 40px rgba(37,12,44,0.45);
  color: #fff;
  padding: 36px;
  position: relative;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  justify-content: center;
  transition: transform .25s ease, box-shadow .25s ease;
}
body.dark .flashcard { background: linear-gradient(135deg,#9b59b6 0%, #a66decff 45%, #6a4c93 100%);}
.flashcard .word { font-size:44px; font-weight:700; letter-spacing:0.6px; margin:0 0 10px; text-transform:capitalize; }
.flashcard .phonetic { font-size:13px; opacity:0.9; margin-bottom:10px; }
.flashcard .meaning { font-size:16px; opacity:0.95; max-height:100px; overflow:auto; margin-bottom:14px; }
.flashcard .usage { font-size:14px; opacity:0.9; margin-top:10px; display:none; } /* revealed on View It */

.card-controls { display:flex; gap:12px; align-items:center; margin-top:18px; }
.btn-view { background:#fff;color:#6a4c93;border-radius:999px;padding:10px 18px;font-weight:700;border:none;cursor:pointer; }
.btn-icon { background:transparent;border:none;color:#fff;font-size:20px;cursor:pointer;padding:8px;border-radius:8px }
.btn-icon.heart.active { color:#ff4b6b; text-shadow:0 4px 20px rgba(255,75,107,0.2) }
.btn-icon.bookmark.active { color:#ffd26f; }

/* nav arrows */
.nav-arrows { display:flex; gap:16px; justify-content:center; margin-top:16px; }
.nav-arrows button { width:48px;height:48px;border-radius:50%;border:none;background:rgba(255,255,255,0.12);color:#fff;font-size:18px;cursor:pointer }

/* small list of cards on the right */
.side-list { width:320px; max-width:40%; display:flex; flex-direction:column; gap:12px; }
.side-item { background:var(--card-light); border-radius:12px; padding:12px; display:flex; gap:10px; align-items:center; justify-content:space-between; cursor:pointer; box-shadow:0 6px 20px rgba(0,0,0,0.06) }
body.dark .side-item { background:var(--card-dark); color:var(--text-dark) }

/* responsive */
@media (max-width: 980px) {
  .flashcard-wrapper { flex-direction:column; align-items:center; padding:12px }
  .side-list { width:100% }
  .flashcard { height:360px; padding:24px }
}
.search-bar {
    display: flex;
    justify-content: center;
    margin: 20px 0;
}

.search-bar form {
    display: flex;
    align-items: center;
    gap: 10px;
}

.search-bar input[type="text"] {
    padding: 10px 14px;
    border: 2px solid #6c63ff; 
    border-radius: 8px;
    outline: none;
    width: 300px;
    font-size: 14px;
    transition: 0.3s;
}

.search-bar input[type="text"]:focus {
    border-color: #4e47d1;
    box-shadow: 0 0 8px rgba(108, 99, 255, 0.5);
}

.search-btn {
    background: linear-gradient(135deg, #6c63ff, #4e47d1);
    color: white;
    border: none;
    padding: 10px 18px;
    border-radius: 8px;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.3s ease;
    font-weight: 500;
    letter-spacing: 0.5px;
    margin-left: 10px; 
    margin-top: 0px;
}

.search-btn:hover {
    background: linear-gradient(135deg, #4e47d1, #6c63ff);
    box-shadow: 0 4px 12px rgba(108, 99, 255, 0.4);
    transform: translateY(-2px);
    
}

body.dark .search-bar .form-control,
body.dark .search-input {
    background-color: #2c2c3c;
    border: 1px solid #555;
    color: #eee;
}
h1 {
  display: inline-block;
  background: linear-gradient(90deg, #b57aff, #ffd26f, #9b59b6);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  animation: floaty 3s ease-in-out infinite;
}
@keyframes floaty {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-5px); }
}

.container{max-width:1200px;margin:28px auto;padding:18px; margin-left:250px;}

.lb-header h2{  display: inline-block;
  background: linear-gradient(90deg, #b57aff, #ffd26f, #9b59b6);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  animation: floaty 3s ease-in-out infinite;
}
@keyframes floaty {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-5px); }
}
.lb-header .subtitle{color:var(--muted);margin-top:6px;font-size:13px}

/* podium + controls row */
    .top-row{display:flex;gap:18px;margin-top:18px;flex-wrap:wrap}
    .podium {
      flex: 1 1 480px;
      display:flex;align-items:flex-end;justify-content:center;gap:18px;padding:18px;border-radius:16px;
      background: linear-gradient(180deg, rgba(155,89,182,0.06), rgba(155,89,182,0.03));
      box-shadow: var(--shadow);
    }
    .podium .place {
      width:140px;height:190px;border-radius:14px;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;padding:12px;
      background: linear-gradient(180deg, #fff, #fff);
      transition: transform .36s cubic-bezier(.2,.9,.2,1);
      position:relative; overflow:visible;
    }
    body.dark .podium .place { background: linear-gradient(180deg,#15131a,#1f1b27) }
    .place.top-1 { height:230px; transform:translateY(-12px); box-shadow:0 20px 40px rgba(106,76,147,0.12) }
    .place .avatar { width:72px;height:72px;border-radius:18px;object-fit:cover;border:4px solid rgba(255,255,255,0.85); box-shadow:0 10px 30px rgba(0,0,0,0.06); }
    .place .name { margin-top:10px;font-weight:700;font-size:15px }
    .place .meta { font-size:12px;color:var(--muted);margin-top:6px }
    .crown { position:absolute; top:40px; left:50%; transform:translateX(-50%); font-size:34px; color:var(--accent3); text-shadow:0 8px 20px rgba(255,210,111,0.15) }

    /* controls */
    .controls { display:flex;gap:10px;align-items:center }
    .search { display:flex; gap:8px; align-items:center; background:var(--card-light); padding:8px;border-radius:12px; box-shadow:0 6px 18px rgba(0,0,0,0.04) }
    body.dark .search { background: rgba(255,255,255,0.03) }
    .search input{border:0;outline:none;padding:8px 10px;font-size:14px;border-radius:8px;background:transparent}
    .select, .btn { padding:8px 12px;border-radius:10px;border:0;cursor:pointer;font-weight:600 }
    .btn { background:linear-gradient(135deg,var(--accent1),var(--accent2)); color:#fff }
    .btn.ghost { background:transparent;border:1px solid rgba(0,0,0,0.06); color:var(--text) }
    body.dark .btn.ghost { border-color: rgba(255,255,255,0.06) }

    /* leaderboard table */
    .lb-table-wrap { margin-top:20px; border-radius:14px; overflow:hidden; box-shadow:0 22px 60px rgba(106,76,147,0.08) }
    table.lb-table { width:100%; border-collapse:collapse; background:var(--card-light); min-width:780px }
    body.dark table.lb-table { background:#17161a }
    th, td { padding:12px 16px; text-align:left; font-size:14px; border-bottom:1px solid rgba(0,0,0,0.04) }
    body.dark th, body.dark td { border-color: rgba(255,255,255,0.04) }
    thead th { background: linear-gradient(90deg,var(--accent2),var(--accent1)); color:#fff; font-weight:700; font-size:13px }
    .rank-cell .medal { display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;border-radius:10px;font-weight:800;color:#fff }
    .medal.top-1 { background: linear-gradient(90deg,#ffd26f,#ffb74d); color:#2a1a00; font-size:15px; box-shadow:0 8px 22px rgba(255,210,111,0.18) }
    .medal.top-2 { background: linear-gradient(90deg,#e8e8e8,#d0d0d0); color:#2a2a2a; font-size:14px }
    .medal.top-3 { background: linear-gradient(90deg,#ffd6a8,#ffb78a); color:#2a1a00; font-size:14px }
    .player { display:flex;align-items:center;gap:12px }
    .player .avatar-sm { width:52px;height:52px;border-radius:12px;object-fit:cover }
    .player .meta { display:flex;flex-direction:column }
    .player .meta .name { font-weight:700 }
    .player .meta .sub { font-size:12px;color:var(--muted) }

    .xp-badge { background:linear-gradient(90deg,var(--accent1),var(--accent2));color:#fff;padding:8px 12px;border-radius:999px;font-weight:700;display:inline-block; }
    .badge-count { background:linear-gradient(90deg,#fff3d9,#ffe5b5); padding:6px 10px; border-radius:999px; font-weight:700; color:#5a3a00 }
    .updated-on { color:var(--muted); font-size:13px }

    /* message button */
    .msg-btn { border:0;background:transparent; cursor:pointer; font-size:16px; padding:8px;border-radius:10px; transition:all .18s }
    .msg-btn:hover { transform:translateY(-3px); background:rgba(0,0,0,0.04) }
    body.dark .msg-btn:hover { background:rgba(255,255,255,0.03) }

    /* responsive */
    @media(max-width:980px){
      .podium{flex-direction:row;justify-content:space-between}
      .podium .place{width:28%}
      table.lb-table{font-size:13px}
    }

    /* nice entry animation */
    .fade-in { opacity:0; transform:translateY(10px); animation:fadeIn 0.6s ease forwards }
    @keyframes fadeIn { to { opacity:1; transform:none } }

    /* === Podium Cards === */
.podium {
  flex: 1 1 480px;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  gap: 20px;
  padding: 24px;
  border-radius: 20px;
  background: linear-gradient(180deg, rgba(155,89,182,0.09), rgba(155,89,182,0.04));
  box-shadow: var(--shadow-lg);
  position: relative;
  overflow: hidden;
}
.podium::before {
  content: "";
  position: absolute;
  bottom: 0;
  left: 5%;
  right: 5%;
  height: 20px;
  border-radius: 10px;
  background: rgba(155,89,182,0.12);
  filter: blur(12px);
}

/* Individual Places */
.place {
  width: 130px;
  height: 190px;
  border-radius: 18px;
  background: linear-gradient(180deg, #fff, #f9f9ff);
  box-shadow: var(--shadow-sm);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: flex-end;
  padding: 16px;
  position: relative;
  transition: all 0.4s ease;
}
body.dark .place {
  background: linear-gradient(180deg, #1e1a27, #2b2338);
}
.place:hover {
  transform: translateY(-10px) scale(1.04);
  box-shadow: 0 18px 40px rgba(155,89,182,0.25);
}

/* Heights for 1,2,3 */
.place.top-1 {
  height: 220px;
  transform: translateY(-12px);
  background: linear-gradient(180deg, #fff9e8, #fff1beff);
}
body.dark .place.top-1 {
  background: linear-gradient(180deg, #332b13, #5a4515ff);
}
.place.top-2 {
  height: 210px;
}
.place.top-3 {
  height: 200px;
}

.place .avatar {
  width: 80px;
  height: 80px;
  border-radius: 20px;
  border: 4px solid #fff;
  object-fit: cover;
  box-shadow: 0 12px 28px rgba(0,0,0,0.15);
}
.place .name {
  margin-top: 8px;
  font-weight: 700;
  font-size: 15px;
}
.place .meta {
  font-size: 13px;
  color: var(--muted);
}
.crown {
  position: absolute;
  top: -18px;
  left: 50%;
  transform: translateX(-50%) rotate(-8deg);
  font-size: 36px;
  animation: floatCrown 3s ease-in-out infinite;

}
@keyframes floatCrown {
  0%, 100% { transform: translate(-50%, -2px) rotate(-8deg); }
  50% { transform: translate(-50%, -8px) rotate(-8deg); }
}

/* === Summary Card === */
.summary-card {
  width: 340px;
  padding: 20px 24px;
  border-radius: 18px;
  background: linear-gradient(135deg, rgba(255,255,255,0.7), rgba(255,255,255,0.4));
  backdrop-filter: blur(12px);
  box-shadow: var(--shadow-lg);
  border: 1px solid rgba(155,89,182,0.15);
  color: #222;
  transition: all 0.4s ease;
}
body.dark .summary-card {
  background: linear-gradient(135deg, rgba(22,22,28,0.8), rgba(18,18,24,0.6));
  border-color: rgba(255,255,255,0.08);
  color: #eee;
}
.summary-card:hover {
  transform: translateY(-5px);
  box-shadow: 0 25px 45px rgba(106,76,147,0.25);
}
.summary-card h4 {
  font-weight: 700;
  color: var(--lilac-1);
  margin-bottom: 10px;
}
.summary-card p {
  font-size: 14px;
  color: var(--muted);
  margin: 6px 0;
}
.summary-card .btn {
  margin-top: 10px;
  background: linear-gradient(135deg, var(--lilac-1), var(--lilac-2));
  border: none;
  color: #fff;
  border-radius: 8px;
  padding: 8px 16px;
  transition: transform .2s, box-shadow .2s;
}
.summary-card .btn:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 16px rgba(106,76,147,0.3);
}

/* === Table Enhancements === */
.lb-table-wrap {
  margin-top: 24px;
  border-radius: 16px;
  overflow: hidden;
  box-shadow: var(--shadow-lg);
  transition: all 0.4s ease;
}
.lb-table-wrap:hover {
  transform: translateY(-3px);
}
table.lb-table tr {
  transition: background 0.25s ease, transform 0.25s ease;
}
table.lb-table tr:hover {
  background: rgba(155,89,182,0.05);
  transform: scale(1.01);
}
body.dark table.lb-table tr:hover {
  background: rgba(155,89,182,0.15);
}

/* Subtle fade-in for everything */
.fade-in {
  opacity: 0;
  transform: translateY(12px);
  animation: fadeUp 0.7s ease forwards;
}
@keyframes fadeUp {
  to {
    opacity: 1;
    transform: translateY(0);
  }
}/* === Podium Cards === */
.podium {
  flex: 1 1 480px;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  gap: 20px;
  padding: 24px;
  border-radius: 20px;
  background: linear-gradient(180deg, rgba(155,89,182,0.09), rgba(155,89,182,0.04));
  box-shadow: var(--shadow-lg);
  position: relative;
  overflow: hidden;
}
.podium::before {
  content: "";
  position: absolute;
  bottom: 0;
  left: 5%;
  right: 5%;
  height: 20px;
  border-radius: 10px;
  background: rgba(155,89,182,0.12);
  filter: blur(12px);
}

/* Individual Places */
.place {
  width: 130px;
  height: 190px;
  border-radius: 18px;
  background: linear-gradient(180deg, #fff, #f9f9ff);
  box-shadow: var(--shadow-sm);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: flex-end;
  padding: 16px;
  position: relative;
  transition: all 0.4s ease;
}
body.dark .place {
  background: linear-gradient(180deg, #1e1a27, #2b2338);
}
.place:hover {
  transform: translateY(-10px) scale(1.04);
  box-shadow: 0 18px 40px rgba(155,89,182,0.25);
}

/* Heights for 1,2,3 */
.place.top-1 {
  height: 220px;
  transform: translateY(-12px);
  background: linear-gradient(180deg, #fff9e8, #fff3c9);
}
body.dark .place.top-1 {
  background: linear-gradient(180deg, #332b13, #b78c29ff);
}
.place.top-2 {
  height: 210px;
}
.place.top-3 {
  height: 200px;
}

.place .avatar {
  width: 80px;
  height: 80px;
  border-radius: 20px;
  border: 4px solid #fff;
  object-fit: cover;
  box-shadow: 0 12px 28px rgba(0,0,0,0.15);
}
.place .name {
  margin-top: 8px;
  font-weight: 700;
  font-size: 15px;
}
.place .meta {
  font-size: 13px;
  color: var(--muted);
}
.crown {
  position: absolute;
  top: 48px;
  left: 48%;
  transform: translateX(-50%) rotate(-8deg);
  font-size: 36px;
  animation: floatCrown 3s ease-in-out infinite;
}
@keyframes floatCrown {
  0%, 100% { transform: translate(-50%, -2px) rotate(-8deg); }
  50% { transform: translate(-50%, -8px) rotate(-8deg); }
}

/* === Summary Card === */
.summary-card {
  width: 340px;
  padding: 20px 24px;
  border-radius: 18px;
  background: linear-gradient(135deg, rgba(255,255,255,0.7), rgba(255,255,255,0.4));
  backdrop-filter: blur(12px);
  box-shadow: var(--shadow-lg);
  border: 1px solid rgba(155,89,182,0.15);
  color: #222;
  transition: all 0.4s ease;
}
body.dark .summary-card {
  background: linear-gradient(135deg, rgba(22,22,28,0.8), rgba(18,18,24,0.6));
  border-color: rgba(255,255,255,0.08);
  color: #eee;
}
.summary-card:hover {
  transform: translateY(-5px);
  box-shadow: 0 25px 45px rgba(106,76,147,0.25);
}
.summary-card h4 {
  font-weight: 700;
  color: var(--lilac-1);
  margin-bottom: 10px;
}
.summary-card p {
  font-size: 14px;
  color: var(--muted);
  margin: 6px 0;
}
.summary-card .btn {
  margin-top: 10px;
  background: linear-gradient(135deg, var(--lilac-1), var(--lilac-2));
  border: none;
  color: #fff;
  border-radius: 8px;
  padding: 8px 16px;
  transition: transform .2s, box-shadow .2s;
}
.summary-card .btn:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 16px rgba(106,76,147,0.3);
}

/* === Table Enhancements === */
.lb-table-wrap {
  margin-top: 24px;
  border-radius: 16px;
  overflow: hidden;
  box-shadow: var(--shadow-lg);
  transition: all 0.4s ease;
}
.lb-table-wrap:hover {
  transform: translateY(-3px);
}
table.lb-table tr {
  transition: background 0.25s ease, transform 0.25s ease;
}
table.lb-table tr:hover {
  background: rgba(155,89,182,0.05);
  transform: scale(1.01);
}
body.dark table.lb-table tr:hover {
  background: rgba(155,89,182,0.15);
}

/* Subtle fade-in for everything */
.fade-in {
  opacity: 0;
  transform: translateY(12px);
  animation: fadeUp 0.7s ease forwards;
}
@keyframes fadeUp {
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

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
<body class="<?php echo ($theme_id == 2) ? 'dark' : ''; ?>">

<!-- Sidebar (kept intact) -->
<div class="sidebar">
  <div class="brand">
    <div class="logo-circle">L</div>
    <h3>Lingoland</h3>
  </div>
  <div class="menu">
    <a href="../user_dashboard/user-dashboard.php"><i class="fas fa-home"></i> Home</a>
    <a href="../courses/courses.php"><i class="fas fa-book"></i> Courses</a>
    <a href="../leaderboard/view_leaderboard.php" class="active"><i class="fas fa-trophy"></i> Leaderboard</a>
    <a id="flashToggle"><i class="fas fa-th-large"></i> Flashcards <i class="fas fa-chevron-right"></i></a>
    <div class="sub " id="flashSub">
      <a href="../user_flashcards/user_flashcards.php" >Review Flashcards</a>
      <a href="../user_flashcards/create_flashcard.php">Add New</a>
      <a href="../user_flashcards/bookmark_flashcard.php">Bookmarked Flashcards</a>
    </div>
    <a id="vocabToggle"><i class="fas fa-language"></i> Vocabulary <i class="fas fa-chevron-right"></i></a>
    <div class="sub" id="vocabSub">
      <a href="../user_dashboard_words/user_dashboard_words.php">Study</a>
      <a href="../user_dashboard_words/add_words.php">Your Dictionary</a>
    </div>

    <a href="../user_badge/user_badge.php"><i class="fas fa-award"></i> Badges</a>
     <a href="../user_certificate/user_certificate.php""><i class="fas fa-certificate"></i> <span>Certificates</span></a>
    <a id="writingToggle"><i class="fas fa-robot"></i> AI Writing Assistant<i class="fas fa-chevron-right"></i></a>
    <div class="sub" id="writingSub">
      <a href="../writing_evaluation/writing_evaluation.php">Evaluate Writing</a>
      <a href="../writing_evaluation/my_writing.php">My Writings</a>
    </div>
    <a href="../user_dashboard/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
  </div>
</div>

<!-- Header (kept intact) -->
<div class="header">
  <div class="left">
    <button class="icon-btn" id="sidebarToggle"><i class="fas fa-bars"></i></button>
    <h3>Leaderboard</h3>
  </div>
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
    <button class="icon-btn" id="settingsBtn"><i class="fas fa-cog"></i></button>
    <span id="themeBtn" class="icon-btn">🌓</span>
    <img id="profilePic" src="<?php echo $profile_pic; ?>" alt="profile" style="width:46px;height:46px;border-radius:10px;cursor:pointer;border:2px solid rgba(0,0,0,0.06)">
  </div>
</div>

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

<!-- Main -->
<div class="container fade-in">

    <div class="lb-header">
      <div>
        <h2>Top performers across Lingoland — celebrate progress 🎉</h2>
      </div>

      <div class="controls">
        <div class="search" role="search" aria-label="Search users" style="display:flex; justify-content:center;margin-bottom:12px;">
          <i class="fa fa-search" style="color:var(--muted)"></i>
          <input id="searchInput" placeholder="Search by name, email or age group" style="padding:8px 12px;border-radius:10px;border:2px solid #6c63ff;">
           <button class="btn search-btn" style="padding:8px 12px;border-radius:10px;border:none;background:linear-gradient(135deg,#6c63ff,#4e47d1);color:#fff;">Search</button>
        </div>

        <select id="sortSelect" class="select" aria-label="Sort by">
          <option value="rank_asc">Rank ↑</option>
          <option value="rank_desc">Rank ↓</option>
          <option value="xp_desc" selected>Score ↓</option>
          <option value="xp_asc">Score ↑</option>
          <option value="name_asc">Name A→Z</option>
          <option value="name_desc">Name Z→A</option>
        </select>

        <button id="refreshBtn" class="btn ghost" title="Reset sorting/search">Reset</button>
      </div>
    </div>

    <div class="top-row">
      <!-- podium -->
      <div class="podium" aria-hidden="false">
        <?php
          // Show top 3 from $allRows (ensure indexes)
          $top1 = $allRows[0] ?? null;
          $top2 = $allRows[1] ?? null;
          $top3 = $allRows[2] ?? null;

          // helper to render place
          function render_place($place, $row, $label){
            if (!$row) {
              echo "<div class='place $label' aria-hidden='true'></div>";
              return;
            }
            $name = htmlspecialchars($row['first_name'].' '.$row['last_name']);
            $pic = !empty($row['profile_picture']) ? "../settings/".htmlspecialchars($row['profile_picture']) : "../img/icon9.png";
            $xp = (int)$row['xp'];
            echo "<div class='place $label' role='group' aria-label='Rank {$place}'>
                    ".($place==1 ? "<div class='crown'>👑</div>" : "")."
                    <img class='avatar' src='".htmlspecialchars($pic)."' alt='{$name}'>
                    <div class='name'>{$name}</div>
                    <div class='meta'>• {$xp} pts </div>
                  </div>";
          }
          // Render 2,1,3 visually (center big)
          render_place(2,$top2,'top-2');
          render_place(1,$top1,'top-1');
          render_place(3,$top3,'top-3');
        ?>
      </div>

      <!-- small stats card -->
      <div class="summary-card">
        <h4 style="margin:0 0 8px 0">Leaderboard Summary</h4>
        <p style="margin:0;color:var(--muted)">Total players: <strong><?php echo count($allRows); ?></strong></p>
        <p style="margin-top:10px;color:var(--muted)">Last updated: <strong><?php echo !empty($allRows[0]['last_updated']) ? date('M j, Y', strtotime($allRows[0]['last_updated'])) : '-' ?></strong></p>
        <div style="margin-top:14px;display:flex;gap:8px">
          <button class="btn" id="downloadCsv">Export CSV</button>
        </div>
      </div>
    </div>

    <!-- Table -->
    <div class="lb-table-wrap fade-in" style="margin-top:18px">
      <table class="lb-table" id="leaderboardTable" aria-describedby="leaderboard-description">
        <thead>
          <tr>
            <th style="width:88px">Rank</th>
            <th>Learners</th>
            <th style="width:130px">Score</th>
            <th style="width:110px">Badges</th>
            <th style="width:120px">Updated</th>
          </tr>
        </thead>
        <tbody id="tbodyRows">
          <!-- rows injected by PHP fallback (also used as initial markup) -->
          <?php foreach ($allRows as $r): 
              $uid = (int)$r['user_id'];
              $name = htmlspecialchars($r['first_name'].' '.$r['last_name']);
              $pic  = !empty($r['profile_picture']) ? "../settings/" . htmlspecialchars($r['profile_picture']) : "../img/icon9.png";
              $email = htmlspecialchars($r['email']);
              $age_group = htmlspecialchars($r['age_group'] ?: 'N/A');
              $xp = (int)$r['xp'];
              $badge_count = (int)$r['badge_count'];
              $rank = (int)$r['rank'];
              $updated = htmlspecialchars(date('M j, Y H:i', strtotime($r['last_updated'])));
          ?>
          <tr data-name="<?php echo strtolower($name); ?>" data-email="<?php echo strtolower($email); ?>" data-age="<?php echo strtolower($age_group); ?>" data-xp="<?php echo $xp; ?>" data-rank="<?php echo $rank; ?>">
            <td class="rank-cell"><span class="medal <?php echo ($rank<=3) ? 'top-'.$rank : ''; ?>"><?php echo $rank; ?></span></td>
            <td>
              <div class="player">
                <img class="avatar-sm" src="<?php echo $pic; ?>" alt="<?php echo $name; ?>">
                <div class="meta">
                  <div class="name"><?php echo $name; ?></div>
                  <div class="sub"> • <?php echo $age_group ?: '—'; ?></div>
                </div>
              </div>
            </td>
            <td><span class="xp-badge"><?php echo $xp; ?></span></td>
            <td><span class="badge-count"><?php echo $badge_count; ?></span></td>
            <td><div class="updated-on"><?php echo $updated; ?></div></td>
            
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

  </div>

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
console.log("JS Loaded ✓");

document.getElementById("chatSend").setAttribute("type", "button");

// sidebar toggle small-screen
const sidebarToggle = document.getElementById('sidebarToggle');
if (sidebarToggle) sidebarToggle.addEventListener('click', ()=> document.body.classList.toggle('sidebar-open'));

// Sidebar submenu toggle
const vocabToggle=document.getElementById('vocabToggle');
vocabToggle.addEventListener('click',()=>document.getElementById('vocabSub').classList.toggle('show'));
// Sidebar submenu toggle
const flashToggle=document.getElementById('flashToggle');
flashToggle.addEventListener('click',()=>document.getElementById('flashSub').classList.toggle('show'));

const writingToggle=document.getElementById('writingToggle');
writingToggle.addEventListener('click', ()=> document.getElementById('writingSub').classList.toggle('show'));

// settings panel
const settingsBtn = document.getElementById('settingsBtn');
const settingsPanel = document.getElementById('settingsPanel');
const settingsClose = document.getElementById('settingsClose');
if (settingsBtn) settingsBtn.addEventListener('click', ()=> settingsPanel.classList.add('open'));
if (settingsClose) settingsClose.addEventListener('click', ()=> settingsPanel.classList.remove('open'));

// Popup toggles
const notifBtn=document.getElementById('notifBtn');

notifBtn.onclick=()=>{document.getElementById('notifPop').style.display=document.getElementById('notifPop').style.display==='block'?'none':'block';document.getElementById('msgPop').style.display='none';};


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


// Chatbot
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


// Animation
window.addEventListener('load',()=>{document.querySelectorAll('.stagger').forEach((el,i)=>{setTimeout(()=>{el.classList.add('enter');},i*120);});});

// initial dataset from server (already in DOM too)
const rowsData = <?php echo $rows_json ?: '[]'; ?>;

const tbody = document.getElementById('tbodyRows');
const searchInput = document.getElementById('searchInput');
const sortSelect = document.getElementById('sortSelect');
const refreshBtn = document.getElementById('refreshBtn');

// Only initialize leaderboard functions if elements exist
if (tbody && searchInput && sortSelect && refreshBtn) {
  // Utility: apply current filter & sort on existing DOM rows (simple approach)
  function applyFilterSort(){
    const q = (searchInput.value || '').trim().toLowerCase();
    const sort = sortSelect.value;

    // gather rows into array from DOM
    const nodes = Array.from(tbody.querySelectorAll('tr'));
    const filtered = nodes.filter(tr=>{
      if (!q) return true;
      const name = tr.getAttribute('data-name')||'';
      const email = tr.getAttribute('data-email')||'';
      const age = tr.getAttribute('data-age')||'';
      return name.includes(q) || email.includes(q) || age.includes(q);
    });

    filtered.sort((a,b)=>{
      const axp = parseInt(a.getAttribute('data-xp')||0,10);
      const brxp = parseInt(b.getAttribute('data-xp')||0,10);
      const ar = parseInt(a.getAttribute('data-rank')||0,10);
      const br = parseInt(b.getAttribute('data-rank')||0,10);
      const an = a.getAttribute('data-name')||'';
      const bn = b.getAttribute('data-name')||'';
      switch(sort){
        case 'xp_desc': return brxp - axp;
        case 'xp_asc': return axp - brxp;
        case 'rank_asc': return ar - br;
        case 'rank_desc': return br - ar;
        case 'name_asc': return an.localeCompare(bn);
        case 'name_desc': return bn.localeCompare(an);
        default: return ar - br;
      }
    });

    // clear and append
    tbody.innerHTML = '';
    filtered.forEach(n => tbody.appendChild(n));
  }

  searchInput.addEventListener('input', ()=> applyFilterSort());
  sortSelect.addEventListener('change', ()=> applyFilterSort());
  refreshBtn.addEventListener('click', ()=>{
    searchInput.value = '';
    sortSelect.value = 'xp_desc';
    // restore original DOM order by re-building from server-provided rows (rowsData)
    rebuildFromData();
  });

  // rebuild table rows from rowsData (useful on reset)
  function rebuildFromData(){
    tbody.innerHTML = '';
    if (rowsData && rowsData.length > 0) {
      rowsData.forEach(r=>{
        const tr = document.createElement('tr');
        const name = (r.first_name + ' ' + r.last_name).trim();
        const pic = r.profile_picture ? ("../settings/" + r.profile_picture) : "../img/icon9.png";
        const email = r.email || '';
        const age = r.age_group || '';
        tr.setAttribute('data-name', name.toLowerCase());
        tr.setAttribute('data-age', (age||'').toLowerCase());
        tr.setAttribute('data-xp', r.xp || 0);
        tr.setAttribute('data-rank', r.rank || 0);

        tr.innerHTML = `
          <td class="rank-cell"><span class="medal ${r.rank<=3 ? 'top-'+r.rank : ''}">${r.rank}</span></td>
          <td>
            <div class="player">
              <img class="avatar-sm" src="${pic}" alt="${name}">
              <div class="meta">
                <div class="name">${escapeHtml(name)}</div>
                <div class="sub"> • ${age || '—'}</div>
              </div>
            </div>
          </td>
          <td><span class="xp-badge">${r.xp || 0}</span></td>
          <td><span class="badge-count">${r.badge_count || 0}</span></td>
          <td><div class="updated-on">${r.last_updated ? new Date(r.last_updated).toLocaleString() : '-'}</div></td>
        `;
        tbody.appendChild(tr);
      });
    }
  }

  // small helper to escape HTML
  function escapeHtml(s){ return (s||'').toString().replace(/[&<>"'`]/g, function(m){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;','`':'&#96;'}[m];}); }

  // export CSV simple
  document.getElementById('downloadCsv')?.addEventListener('click', ()=>{
    const rows = Array.from(document.querySelectorAll('#tbodyRows tr'));
    const csv = [['rank','name','email','age_group','xp','badges','updated']].concat(rows.map(tr=>{
      return [
        tr.querySelector('.medal')?.textContent?.trim() || '',
        (tr.querySelector('.meta .name')?.textContent || '').trim(),
        (tr.getAttribute('data-email') || '').trim(),
        (tr.getAttribute('data-age') || '').trim(),
        (tr.getAttribute('data-xp') || '').trim(),
        (tr.querySelector('.badge-count')?.textContent || '').trim(),
        (tr.querySelector('.updated-on')?.textContent || '').trim()
      ];
    }));
    const csvStr = csv.map(r => r.map(c => '"' + (''+c).replace(/"/g,'""') + '"').join(',')).join('\n');
    const blob = new Blob([csvStr], {type: 'text/csv;charset=utf-8;'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a'); a.href = url; a.download = 'leaderboard.csv'; a.click(); URL.revokeObjectURL(url);
  });

  // initial sort (apply)
  applyFilterSort();
}

// Only run podium animation if podium exists
const podiumPlaces = document.querySelectorAll('.podium .place');
if (podiumPlaces.length > 0) {
  window.addEventListener('load', ()=> {
    document.querySelectorAll('.podium .place').forEach((el,i)=>{
      setTimeout(()=> el.style.transform = (el.classList.contains('top-1') ? 'translateY(-14px) scale(1.02)' : ''), 200 + i*120);
    });
  });
}

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
<?php
// close DB
mysqli_close($conn);
?>
