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

// Get theme
$sql = "SELECT theme_id FROM sets WHERE user_id = ? ORDER BY set_on DESC LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$theme_id = 1;
if ($row = $result->fetch_assoc()) {
    $theme_id = $row['theme_id'];
}

$profile_id = null;
$sql_profile = "SELECT profile_id FROM user_profile WHERE user_id = ?";
$stmt_p = $conn->prepare($sql_profile);
$stmt_p->bind_param("i", $user_id);
$stmt_p->execute();
$res_p = $stmt_p->get_result();
if ($row_p = $res_p->fetch_assoc()) $profile_id = $row_p['profile_id'];

// --- GET quiz_id from URL ---
if (!isset($_POST['quiz_id'])) {
    die("Quiz ID missing in URL.");
}
$quiz_id = intval($_POST['quiz_id']);


// ---------- FETCH QUIZ TITLE + TIME LIMIT ----------
$quiz_sql = "SELECT title, time_limit FROM quiz WHERE quiz_id=?";
$qs = $conn->prepare($quiz_sql);
$qs->bind_param("i", $quiz_id);
$qs->execute();
$quiz_res = $qs->get_result();
$quiz = $quiz_res->fetch_assoc();

if (!$quiz) die("Quiz not found.");

$quiz_title = $quiz["title"];
$time_limit = intval($quiz["time_limit"]); // seconds

// ---------- FETCH QUESTIONS + OPTIONS ----------
$q_sql = "SELECT question_id, question_text, option_1, option_2, option_3, option_4, correct_answer
          FROM question WHERE quiz_id = ?";
$qst = $conn->prepare($q_sql);
$qst->bind_param("i", $quiz_id);
$qst->execute();
$q_data = $qst->get_result();

$questions = [];
while ($row = $q_data->fetch_assoc()) {
    $questions[] = $row;
}

// ---------- DETERMINE ATTEMPT NUMBER ----------
$attempt_check = "SELECT COUNT(*) AS attempt_count 
                  FROM quiz_attempt 
                  WHERE user_id=? AND quiz_id=?";
$ac = $conn->prepare($attempt_check);
$ac->bind_param("ii", $user_id, $quiz_id);
$ac->execute();
$ac_res = $ac->get_result();
$ac_row = $ac_res->fetch_assoc();
$attempt_number = $ac_row["attempt_count"] + 1;

// ---------- INSERT NEW QUIZ ATTEMPT ----------
$insert_attempt = "INSERT INTO quiz_attempt (quiz_id, user_id, attempt_number, start_time, score)
                   VALUES (?, ?, ?, NOW(), 0)";
$ia = $conn->prepare($insert_attempt);
$ia->bind_param("iii", $quiz_id, $user_id, $attempt_number);
$ia->execute();

// get attempt_id auto ID
$attempt_id = $conn->insert_id;

// ---------- LOG ACTIVITY ----------
$log = $conn->prepare("INSERT INTO activity_log (user_id, action_type, action_time) VALUES (?, 'quiz started', NOW())");
if (!$log) {
    die("Prepare failed: " . $conn->error . " | SQL: " . $sql_log);
}

$log->bind_param("i", $user_id);
$log->execute();

// ---------- SEND QUESTIONS TO JS ----------
$questions_json = json_encode($questions);

$attempt_id = mysqli_insert_id($conn);

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

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Lingoland — Quiz Arena</title>

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
      --accent-1: #ffd26f;
      --accent-2: #b57aff;
       --active-bg: rgba(255,255,255,0.25);
   --active-text: #ffd26f;
    }
    *{box-sizing:border-box}
    body {
      margin: 0;
      font-family: 'Poppins', 'Nunito', system-ui, sans-serif;
      background: var(--bg-light);
      color: var(--text-light);
      -webkit-font-smoothing:antialiased;
      -moz-osx-font-smoothing:grayscale;
      transition: background .28s ease, color .28s ease;
      min-height:100vh;
    }
    body.dark {
      background: var(--bg-dark);
      color: var(--text-dark);
    }

.menu a.active { 
      background:var(--active-bg);
      color:var(--active-text);
      font-weight:700;
      transform:translateX(6px);
    } 

 .menu .sub a.active{font-weight:500; font-size:14px; padding-left:18px; color:rgba(255,255,255,0.9); background:var(--active-bg);}
    .sub{display:none;margin-left:18px;flex-direction:column;gap:6px}
    .sub a{font-size:14px;color:rgba(255,255,255,0.9)}
    .sub.show{display:flex;animation:fadeIn .25s ease-in-out}
    @keyframes fadeIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}

   

body.dark .chat-window {
  background: radial-gradient(circle at top left, #1c122b, #0f0a1a 80%);
  color: #e9e6ff;
}



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


    /* HEADER (same as dashboard) */
    .header {
      position: fixed; left:240px; right:0; top:0; height:64px;
      display:flex; align-items:center; justify-content:space-between;
      padding:10px 20px; background: var(--card-light); border-bottom:1px solid rgba(0,0,0,0.04);
      z-index: 70; transition: background .24s;
    }
    body.dark .header { background: var(--card-dark); border-bottom:1px solid rgba(255,255,255,0.04) }
    .header .left {display:flex;align-items:center; gap:14px}
    .header .right { display:flex; align-items:center; gap:12px; position:relative }
    .icon-btn{ background:transparent;border:0;padding:8px;border-radius:8px;cursor:pointer;font-size:16px; }

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


    
    /* settings panel */
    .settings-panel{ position: fixed; right:-360px; top:0; width:340px; height:100vh; background:var(--card-light);
      box-shadow:-16px 0 40px rgba(0,0,0,0.14); padding:18px; transition:right .32s ease; z-index:90; }
    .settings-panel.open{ right:0; }
    .settings-close{ position:absolute; top:10px; right:14px; background:none; border:none; color:var(--muted); font-size:20px; cursor:pointer; }
    body.dark .settings-panel{ background:var(--card-dark); }
    
    .popup {
      position:absolute; top:62px; right:20px; width:300px; display:none; background:var(--card-light); border-radius:10px;
      box-shadow:0 12px 30px rgba(0,0,0,0.12); padding:12px; z-index:80;
    }
    body.dark .popup { background:var(--card-dark) }
    .popup.show { display:block }

    /* MAIN area */
    main{ margin-left:240px; padding:94px 32px 48px 32px; min-height:100vh; transition: all .4s ease; }

    /* QUIZ LAYOUT */
    .quiz-wrap {
      display:flex; gap:20px; align-items:stretch;
      justify-content:center;
      width:100%;
      max-width:1200px;
      margin: 0 auto;
      transition: all .4s ease;
    }

    /* LEFT - progress column */
    .quiz-side {
      width:120px;
      display:flex;
      flex-direction:column;
      gap:18px;
      align-items:center;
      justify-content:flex-start;
    }
    .score-ring {
      width:110px; height:110px; border-radius:50%;
      display:flex;align-items:center;justify-content:center;
      background: conic-gradient(var(--lilac-1) 0deg, var(--lilac-2) 90deg, rgba(0,0,0,0.06) 0deg);
      box-shadow:0 10px 28px rgba(11,11,12,0.06);
      position:relative;
      transition: all .4s ease;
    }
    .score-ring .score-inner {
      width:80px;height:80px;border-radius:50%;background:var(--card-light);display:flex;align-items:center;justify-content:center;font-weight:700;
      color:var(--text-light); box-shadow: inset 0 2px 6px rgba(0,0,0,0.04);
    }
    body.dark .score-ring .score-inner { background:var(--card-dark); color:var(--text-dark) }

    .q-count { font-size:13px;color:var(--muted); margin-top:6px; text-align:center }

    /* RIGHT - quiz card */
    .quiz-card {
      flex:1; min-width:420px; max-width:900px;
      background: linear-gradient(180deg, rgba(255,255,255,0.98), rgba(250,246,255,0.95));
      border-radius:18px;
      padding:22px;
      box-shadow:0 18px 50px rgba(11,11,12,0.06);
      position:relative;
      overflow:hidden;
      transition: all .36s cubic-bezier(.2,.9,.2,1);
    }
    body.dark .quiz-card { background: linear-gradient(180deg,#1a1420,#23142c); }

    /* Question gradient box (like image) */
    .question-box {
      border-radius:12px;
      padding:28px;
      min-height:140px;
      display:flex;align-items:center;justify-content:center;text-align:center;
      font-weight:700;color:var(--text-light);
      background: linear-gradient(90deg, #fce6d6 0%, #ffd2b3 35%, #f6d1ff 72%);
      box-shadow: inset 0 -6px 20px rgba(0,0,0,0.02);
      transition: transform .35s ease, box-shadow .35s ease, background .35s ease;
    }
    body.dark .question-box { background: linear-gradient(90deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01)); color:var(--text-dark) }

    /* time bar below question */
    .time-row { display:flex; align-items:center; gap:12px; margin-top:12px; }
    .time-track { flex:1; height:10px; border-radius:8px; background:rgba(0,0,0,0.06); overflow:hidden; }
    .time-progress { height:100%; width:0%; background: linear-gradient(90deg,#ffd26f,#b57aff,#9b59b6); transition: width .3s linear; }

    .time-label { width:56px; text-align:right; font-weight:600; color:var(--muted); font-size:13px; }

    /* Options */
    .options { margin-top:18px; display:flex; flex-direction:column; gap:12px; }
    .option {
      padding:12px 14px; border-radius:10px; background:var(--card-light); cursor:pointer;
      box-shadow:0 6px 18px rgba(11,11,12,0.04); display:flex; align-items:center; gap:12px; transition: all .26s ease;
    }
    body.dark .option { background: #121217; box-shadow: 0 6px 18px rgba(0,0,0,0.14); }
    .option:hover { transform: translateY(-4px); }
    .option .label { width:36px;height:36;border-radius:8px;display:flex;align-items:center;justify-content:center;font-weight:700;background:rgba(0,0,0,0.04);color:var(--muted) }
    .option .text { flex:1; font-weight:600; color:var(--text-light) }
    body.dark .option .text { color:var(--text-dark) }

    /* selected option gradient */
    .option.selected {
      background: linear-gradient(90deg,#ffd26f,#b57aff 60%, #9b59b6);
      color: #fff;
      box-shadow: 0 18px 50px rgba(150,90,170,0.12);
      transform: translateY(-6px) scale(1.01);
    }
    .option.selected .label { background: rgba(255,255,255,0.15); color: #fff; }

     body.dark .question-box {
  background: linear-gradient(90deg,#ffd26f,#b57aff 60%, #9b59b6);
  color: var(--text-dark);
}
body.dark .option.selected {
  background: linear-gradient(90deg,#ffd26f,#b57aff 60%, #9b59b6);
  color: #fff;
}

    /* bottom controls */
    .controls { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-top:18px; }
    .btn {
      padding:10px 16px; border-radius:10px; border:0; font-weight:700; cursor:pointer; transition: all .22s ease;
    }
    .btn.secondary { background:transparent; border:1px solid rgba(0,0,0,0.06); color:var(--muted) }
    .btn.primary { background:var(--lilac-1); color:white; box-shadow:0 12px 30px rgba(106,76,147,0.18) }
    .btn.primary:hover { transform: translateY(-3px); }

    /* summary overlay */
    .summary {
      position:absolute; inset:0; display:flex;align-items:center;justify-content:center;
      background: linear-gradient(180deg, rgba(255,255,255,0.96), rgba(255,255,255,0.98));
      backdrop-filter: blur(6px);
      z-index:30; visibility:hidden; opacity:0; transform: scale(.98); transition: all .36s ease;
    }
    .summary.show { visibility:visible; opacity:1; transform: scale(1); }
    .summary-card {
      width:520px; max-width:94%; padding:22px; border-radius:14px; background:var(--card-light);
      box-shadow:0 24px 60px rgba(11,11,12,0.12); text-align:center;
    }
    body.dark .summary { background: linear-gradient(180deg, rgba(10,10,10,0.6), rgba(10,10,10,0.7)); }
    body.dark .summary-card { background: #14121a; color:var(--text-dark) }

    .summary-card h3 { margin:8px 0; font-size:20px }
    .summary-score { font-size:42px; font-weight:900; margin:6px 0; color:var(--lilac-2) }

    /* small responsive */
    @media (max-width: 1100px) {
      .quiz-wrap { flex-direction:column; align-items:center; }
      .quiz-side { flex-direction:row; width:100%; justify-content:space-around; margin-bottom:12px }
      .quiz-card { width:100%; max-width:900px }
    }

    @media (max-width: 600px) {
      .sidebar { width:72px; }
      .header { left:72px; }
      main { margin-left:72px; padding:86px 12px 48px 12px; }
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
    .chat-window.show { display:block; opacity:1; transform:translateY(0); }
    .chat-window::before {
      content: ''; position:absolute; left:0; right:0; top:0; height:3px;
      background:linear-gradient(90deg,#b57aff,#9b59b6,#b57aff); background-size:200% 100%;
      animation: slidebg 3.5s linear infinite;
    }
    @keyframes slidebg { from{background-position:0 0} to{background-position:200% 0} }

    .chat-header { font-weight:800; font-size:18px; margin-bottom:8px; }
    .chat-sub { color:rgba(255,255,255,0.8); font-size:13px; margin-bottom:8px; }
    .loading-orbs { display:flex; gap:8px; margin:8px 0 12px; }
    .loading-orbs div { width:10px; height:10px; border-radius:50%; background:#b57aff; opacity:.4; animation: orb 1s infinite ease-in-out; }
    .loading-orbs div:nth-child(2){ animation-delay:.16s } .loading-orbs div:nth-child(3){ animation-delay:.32s }
    @keyframes orb { 0%,100%{ transform:scale(.8); opacity:.4 } 50%{ transform:scale(1.2); opacity:1 } }

    .chat-example { background: rgba(255,255,255,0.04); padding:8px 10px; border-radius:8px; margin-top:6px; cursor:pointer; transition:.16s }
    .chat-example:hover { background: rgba(255,255,255,0.08) }

    .chat-input-row { display:flex; gap:8px; align-items:center; margin-top:12px; }
    .chat-input { flex:1; padding:10px; border-radius:10px; border:0; background:rgba(255,255,255,0.06); color:#fff; outline:none; }
    .chat-send { background:linear-gradient(90deg,var(--lilac-2),var(--lilac-1)); color:white; border:0; padding:10px 12px; border-radius:10px; font-weight:700; cursor:pointer; }

    /* entrance animation classes for many elements */
    .enter { opacity:1 !important; transform:none !important; transition: all .36s cubic-bezier(.2,.9,.2,1) !important; }
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
.enroll-btn:hover {
  background: linear-gradient(135deg, #9b59b6, #6a4c93);
  transform: scale(1.05);
  box-shadow: 0 0 12px rgba(155, 89, 182, 0.5);
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

hr {
            border: none; /* Remove default border */
            border-top: 1px solid #ccc; /* Add a custom top border */
            margin: 20px 0; /* Add some vertical spacing */
            margin-right: 230px;
        }
body.dark hr{
  border-top: 1px solid #cccccc65;
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

/* Floating heading animation */
h2 {
  display: inline-block;
  background: linear-gradient(90deg, #b57aff, #ffd26f, #9b59b6);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  animation: floaty 3s ease-in-out infinite;
}
  </style>
</head>
<body  class="<?php echo $body_class; ?>">

  <!-- SIDEBAR -->
  <aside class="sidebar" aria-label="Main sidebar">
    <div class="brand">
      <div class="logo-circle">L</div>
      <div style="line-height:1">
        <div style="font-weight:800">Lingoland</div>
        <div style="font-size:12px;color:rgba(255,255,255,0.9)">Learn • Practice • Grow</div>
      </div>
    </div>

    <nav class="menu" aria-label="Main menu">
       <a href="../user_dashboard/user-dashboard.php"><i class="fas fa-home"></i> Home</a>
    <a href="../courses/courses.php" class="active" class= "active"><i class="fas fa-book"></i> Courses</a>
    <a href="../leaderboard/view_leaderboard.php"><i class="fas fa-trophy"></i> Leaderboard</a>
    <a id="flashToggle"><i class="fas fa-th-large"></i> Flashcards <i class="fas fa-chevron-right"></i></a>
    <div class="sub" id="flashSub">
      <a href="../user_flashcards/user_flashcards.php">Review</a>
      <a href="../user_flashcards/create_flashcard.php">Add New</a>
       <a href="../user_flashcards/bookmark_flashcard.php">Bookmarked Flashcard</a>
    </div>
    <a id="vocabToggle"><i class="fas fa-language"></i> Vocabulary <i class="fas fa-chevron-right"></i></a>
    <div class="sub" id="vocabSub">
       <a href="../user_dashboard_words/user_dashboard_words.php">Study New Words</a>
        <a href="../user_dashboard_words/add_words.php">Your Dictionary</a>
    </div>
    <a href="../user_badge/user_badge.php"><i class="fas fa-award"></i> Badges</a>
     <a href="../user_certificate/user_certificate.php"><i class="fas fa-certificate"></i> <span>Certificates</span></a>
    <a id="writingToggle"><i class="fas fa-robot"></i> AI Writing Assistant<i class="fas fa-chevron-right"></i></a>
    <div class="sub" id="writingSub">
      <a href="../writing_evaluation/writing_evaluation.php">Evaluate Writing</a>
      <a href="../writing_evaluation/my_writing.php">My Writings</a>
    </div>
    <a href="../user_dashboard/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </nav>
  </aside>

  <!-- HEADER -->
  <header class="header" role="banner">
    <div class="left">
      <div class="logo" style="gap:12px;">
        <div class="logo-circle" style="width:44px;height:44px;font-size:18px">L</div>
      </div>
      <div style="font-weight:700; font-size:15px;">Quiz Arena</div>
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



      <button id="settingsBtn" class="icon-btn" title="Settings (spinning)"><i class="fas fa-cog"></i></button>
      <button id="themeBtn" class="icon-btn" title="Toggle theme">🌓</button>
 <img id="profilePic" src="<?php echo $profile_pic; ?>" alt="profile" style="width:46px;height:46px;border-radius:10px;cursor:pointer;border:2px solid rgba(0,0,0,0.06)">
    
      </div>
    </div>
  </header>

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
    <div class="quiz-wrap">

      <!-- left column: progress ring -->
      <div class="quiz-side">
        <div id="scoreRing" class="score-ring" aria-hidden="true">
          <div class="score-inner" id="scoreInner">0%</div>
        </div>
        <div class="q-count" id="qCount">0 / 5</div>
        <div style="font-size:13px;color:var(--muted);text-align:center">Progress</div>
      </div>

      <!-- main quiz card -->
      <div class="quiz-card" role="region" aria-label="Quiz card">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
          <div style="font-weight:700;font-size:16px"><?php echo htmlspecialchars($quiz_title); ?></div>
          <div style="font-size:13px;color:var(--muted)">Question <span id="qIndex">1</span> / <span id="qTotal">5</span></div>
        </div>

        <div class="question-box" id="questionBox" style="margin-top:16px;">
          
        </div>

        <div class="time-row" aria-hidden="true">
          <div style="font-size:13px;color:var(--muted)">Time</div>
          <div class="time-track" aria-hidden="true"><div class="time-progress" id="timeProgress"></div></div>
          <div class="time-label" id="timeLabel">00:30</div>
        </div>

        <div class="options" id="optionsList" role="list">
          <!-- options injected by JS -->
        </div>

        <div class="controls">
          <button class="btn secondary" id="prevBtn" disabled>&larr; Previous</button>
          <div style="display:flex;gap:10px;align-items:center">
            <button class="btn secondary" id="skipBtn">Skip</button>
            <button class="btn primary" id="nextBtn">Next</button>
          </div>
        </div>

        <!-- summary overlay -->
        <div class="summary" id="summaryPanel" aria-hidden="true">
          <div class="summary-card" role="dialog" aria-modal="true">
            <div style="font-size:34px">🎉 Congratulations!</div>
            <h3>You completed the quiz</h3>
            <div class="summary-score" id="finalScore">0 / 5</div>
            <div style="margin-top:8px;color:var(--muted)" id="summaryText">Nice work — keep going to improve your streak!</div>
            <div style="margin-top:14px;display:flex;gap:10px;justify-content:center">
              <button class="btn secondary" id="tryAgainBtn">Try Again</button>
              <button class="btn primary" id="closeSummaryBtn">Continue</button>
            </div>
          </div>
        </div>
      </div>
    </div>
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


  <!-- SCRIPTS -->
<script>

  console.log("JS Loaded ✓");

document.getElementById("chatSend").setAttribute("type", "button");

    const SERVER_QUESTIONS = <?php echo $questions_json; ?>;
    const SERVER_TIME_LIMIT = <?php echo intval($time_limit); ?>; // minutes from DB
    const SERVER_QUIZ_ID = <?php echo intval($quiz_id); ?>;
    const SERVER_ATTEMPT_ID = <?php echo intval($attempt_id); ?>;
    const SERVER_USER_ID = <?php echo intval($user_id); ?>;
    const SERVER_ATTEMPT_NUMBER = <?php echo intval($attempt_number); ?>;

    /************* UI toggles (dashboard parity) *************/
    const notifBtn = document.getElementById('notifBtn');
    const notifPop = document.getElementById('notifPop');
    const msgPop = document.getElementById('msgPop');
    const profilePic = document.getElementById('profilePic');
    const profileMenu = document.getElementById('profileMenu');


    // Safe toggles
    if (notifBtn) notifBtn.addEventListener('click', e => {
        notifPop?.classList.toggle('show');
        msgPop?.classList.remove('show');
        profileMenu?.classList.remove('show');
    });
    
   
    
    if (profilePic) profilePic.addEventListener('click', e => {
        profileMenu?.classList.toggle('show');
        notifPop?.classList.remove('show');
        msgPop?.classList.remove('show');
    });

  // Settings panel
const settingsBtn=document.getElementById('settingsBtn');
const settingsPanel=document.getElementById('settingsPanel');
const settingsClose=document.getElementById('settingsClose');
settingsBtn.onclick=()=>settingsPanel.classList.add('open');
settingsClose.onclick=()=>settingsPanel.classList.remove('open');

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


    // Submenus
    const flashToggle = document.getElementById('flashToggle');
    const vocabToggle = document.getElementById('vocabToggle');
    
    if (flashToggle) flashToggle.addEventListener('click', (e) => {
        e.preventDefault();
        const sub = document.getElementById('flashSub');
        if (sub) sub.classList.toggle('show');
    });
    
    if (vocabToggle) vocabToggle.addEventListener('click', (e) => {
        e.preventDefault();
        const sub = document.getElementById('vocabSub');
        if (sub) sub.classList.toggle('show');
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

    // Close small popups on outside click
    document.addEventListener('click', (e) => {
        if (notifBtn && !notifBtn.contains(e.target) && notifPop && !notifPop.contains(e.target)) 
            notifPop.classList.remove('show');
        if (profilePic && !profilePic.contains(e.target) && profileMenu && !profileMenu.contains(e.target)) 
            profileMenu.classList.remove('show');
    });

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


    /************* QUIZ LOGIC *************/
    
    // Convert server format to client-friendly structure
    // Note: correct_answer contains TEXT like "Written", not 1,2,3,4
    const questionsList = (SERVER_QUESTIONS || []).map(q => ({
        question_id: q.question_id,
        text: q.question_text,
        options: [q.option_1, q.option_2, q.option_3, q.option_4],
        correct_answer_text: q.correct_answer // This is the actual correct answer text
    }));

    const total = questionsList.length;
    
    // DOM refs
    const questionBox = document.getElementById('questionBox');
    const optionsList = document.getElementById('optionsList');
    const qIndexEl = document.getElementById('qIndex');
    const qTotalEl = document.getElementById('qTotal');
    const qCountEl = document.getElementById('qCount');
    const scoreInner = document.getElementById('scoreInner');
    const scoreRing = document.getElementById('scoreRing');
    const timeProgress = document.getElementById('timeProgress');
    const timeLabel = document.getElementById('timeLabel');
    const prevBtn = document.getElementById('prevBtn');
    const nextBtn = document.getElementById('nextBtn');
    const skipBtn = document.getElementById('skipBtn');
    const summaryPanel = document.getElementById('summaryPanel');
    const finalScoreEl = document.getElementById('finalScore');
    const tryAgainBtn = document.getElementById('tryAgainBtn');
    const closeSummaryBtn = document.getElementById('closeSummaryBtn');

    let currentIndex = 0;
    const answers = new Array(total).fill(null); // Store selected option indices (0,1,2,3)
    const userSelections = new Array(total).fill(null); // Store selected option text

    // Timer - Convert minutes to seconds
    let totalQuizTime = Number(SERVER_TIME_LIMIT) * 60 || 300; // Convert minutes to seconds
    let totalRemaining = totalQuizTime;
    let totalTimerHandle = null;

    // Initialize UI
    if (qTotalEl) qTotalEl.textContent = total;

    // Helper to format mm:ss
    function fmtSeconds(sec) {
        const mm = String(Math.floor(sec / 60)).padStart(2, '0');
        const ss = String(sec % 60).padStart(2, '0');
        return `${mm}:${ss}`;
    }

    // Timer UI functions
    function updateTotalTimeUI() {
        if (!timeProgress || !timeLabel) return;
        const elapsed = Math.max(0, totalQuizTime - totalRemaining);
        const pct = Math.min(100, Math.round((elapsed / totalQuizTime) * 100));
        timeProgress.style.width = pct + '%';
        timeLabel.textContent = fmtSeconds(Math.max(0, totalRemaining));
    }

    function startTotalTimer() {
        if (totalTimerHandle) clearInterval(totalTimerHandle);
        totalRemaining = totalQuizTime;
        updateTotalTimeUI();
        totalTimerHandle = setInterval(() => {
            totalRemaining--;
            updateTotalTimeUI();
            if (totalRemaining <= 0) {
                clearInterval(totalTimerHandle);
                showSummary(true); // Time's up
            }
        }, 1000);
    }

    function renderQuestion(index) {
        if (!questionsList[index]) return;
        const q = questionsList[index];

        if (questionBox) questionBox.textContent = q.text;

        if (optionsList) {
            optionsList.innerHTML = '';
            q.options.forEach((opt, i) => {
                const div = document.createElement('div');
                div.className = 'option';
                div.setAttribute('role', 'button');
                div.setAttribute('tabindex', '0');
                div.dataset.optIndex = i;
                div.dataset.optionText = opt;
                div.innerHTML = `
                    <div class="label">${String.fromCharCode(65 + i)}</div>
                    <div class="text">${opt}</div>
                `;
                
                // Set selected if this option was previously selected
                if (answers[index] === i) {
                    div.classList.add('selected');
                }

                // Click handler
                div.addEventListener('click', () => {
                    answers[index] = i;
                    userSelections[index] = opt; // Store the text
                    updateOptionSelection();
                    updateProgressUI();
                    
                    // Check if correct (compare text, case-insensitive)
                    const isCorrect = opt.trim().toLowerCase() === q.correct_answer_text.trim().toLowerCase();
                    
                    // Save answer to server
                    saveAnswer(q.question_id, opt, isCorrect ? 1 : 0);
                });

                // Keyboard support
                div.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        answers[index] = i;
                        userSelections[index] = opt;
                        updateOptionSelection();
                        updateProgressUI();
                        
                        const isCorrect = opt.trim().toLowerCase() === q.correct_answer_text.trim().toLowerCase();
                        saveAnswer(q.question_id, opt, isCorrect ? 1 : 0);
                    }
                });

                optionsList.appendChild(div);
            });
        }

        if (qIndexEl) qIndexEl.textContent = index + 1;
        updateButtons();
        updateProgressUI();
    }

    function updateOptionSelection() {
        if (!optionsList) return;
        const opts = optionsList.querySelectorAll('.option');
        opts.forEach((el, i) => {
            el.classList.toggle('selected', answers[currentIndex] === i);
        });
    }

    function updateProgressUI() {
        const answered = answers.filter(a => a !== null).length;
        if (qCountEl) qCountEl.textContent = `${answered} / ${total}`;
        const pct = total === 0 ? 0 : Math.round((answered / total) * 100);
        if (scoreInner) scoreInner.textContent = pct + '%';
        const deg = Math.round((answered / total) * 360);
        if (scoreRing) scoreRing.style.background = `conic-gradient(var(--lilac-1) 0deg ${deg}deg, rgba(0,0,0,0.06) ${deg}deg 360deg)`;
    }

    function updateButtons() {
        if (prevBtn) prevBtn.disabled = (currentIndex === 0);
        if (nextBtn) nextBtn.textContent = (currentIndex === total - 1) ? 'Finish' : 'Next';
    }

    function goNext() {
        if (currentIndex < total - 1) {
            currentIndex++;
            renderQuestion(currentIndex);
        } else {
            showSummary(false);
        }
    }

    function goPrev() {
        if (currentIndex > 0) {
            currentIndex--;
            renderQuestion(currentIndex);
        }
    }

    // Wire controls
    if (prevBtn) prevBtn.addEventListener('click', goPrev);
    if (nextBtn) nextBtn.addEventListener('click', goNext);
    if (skipBtn) skipBtn.addEventListener('click', () => {
        answers[currentIndex] = null;
        userSelections[currentIndex] = null;
        updateOptionSelection();
        updateProgressUI();
        goNext();
    });

   function computeScore() {
    let correctCount = 0;
    let totalScore = 0;
    const pointsPerCorrect = 10; // Each correct answer = 10 points
    
    for (let i = 0; i < total; i++) {
        if (userSelections[i] !== null) {
            const selectedText = userSelections[i].trim().toLowerCase();
            const correctText = questionsList[i].correct_answer_text.trim().toLowerCase();
            
            if (selectedText === correctText) {
                correctCount++;
                totalScore += pointsPerCorrect;
            }
        }
    }
    
    return {
        correctCount: correctCount,
        totalScore: totalScore
    };
}

   function showSummary(isTimeUp = false) {
    if (totalTimerHandle) {
        clearInterval(totalTimerHandle);
        totalTimerHandle = null;
    }
    
    const result = computeScore();
    const correctCount = result.correctCount;
    const totalScore = result.totalScore;
    const percentage = Math.round((correctCount / total) * 100);
    const emoji = isTimeUp ? '😞' : '🎉';
    const title = isTimeUp ? "Time's Up!" : 'You completed the quiz';
    
    // Create the message
    const msg = isTimeUp 
        ? `Your quiz ended automatically.` 
        : `Well done! You answered ${correctCount} out of ${total} questions correctly.`;
    
    const summaryEmoji = document.querySelector('.summary-card div:first-child');
    const summaryTitle = document.querySelector('.summary-card h3');
    const summaryTextEl = document.getElementById('summaryText');
    const attemptNumberEl = document.getElementById('attemptNumber'); // If you added this element

    if (summaryEmoji) summaryEmoji.textContent = emoji;
    if (summaryTitle) summaryTitle.textContent = title;
    if (summaryTextEl) summaryTextEl.textContent = msg;
    
    // Update final score display
    if (finalScoreEl) {
        // Format: "Score: 20, 2/3 (66%)"
        finalScoreEl.textContent = `Score: ${totalScore}, ${correctCount}/${total} (${percentage}%)`;
    }
    
    // Update attempt number display (if you have the element)
    if (attemptNumberEl) {
        attemptNumberEl.textContent = `Attempt #${SERVER_ATTEMPT_NUMBER}`;
    }

    // Update progress ring (use correctCount for percentage)
    const deg = Math.round((correctCount / total) * 360);
    if (scoreRing) scoreRing.style.background = `conic-gradient(var(--lilac-1) 0deg ${deg}deg, rgba(0,0,0,0.06) ${deg}deg 360deg)`;
    if (scoreInner) scoreInner.textContent = percentage + '%';

    // Save final score to server - send totalScore
    saveFinalScore(totalScore);

    if (summaryPanel) {
        summaryPanel.classList.add('show');
        summaryPanel.setAttribute('aria-hidden', 'false');
    }
}

    // Save answer to server
    async function saveAnswer(questionID, selectedOptionText, isCorrect) {
        try {
            const response = await fetch("save_answer.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    quiz_id: SERVER_QUIZ_ID,
                    attempt_id: SERVER_ATTEMPT_ID,
                    user_id: SERVER_USER_ID,
                    attempt_number: SERVER_ATTEMPT_NUMBER,
                    question_id: questionID,
                    selected_option: selectedOptionText,
                    is_correct: isCorrect
                })
            });
            
            if (!response.ok) {
                console.error("Failed to save answer:", response.status);
            }
        } catch (err) {
            console.error("Error saving answer:", err);
        }
    }


// Save final score
async function saveFinalScore(score) {
    try {
        const response = await fetch("save_final_score.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                // DON'T send attempt_id since your table doesn't have it
                user_id: SERVER_USER_ID,
                quiz_id: SERVER_QUIZ_ID,
                attempt_number: SERVER_ATTEMPT_NUMBER,
                score: score
            })
        });
        
        const result = await response.json();
        console.log("Save final score response:", result);
        
        if (!response.ok || !result.success) {
            console.error("Failed to save final score:", result);
        }
    } catch (err) {
        console.error("Error saving final score:", err);
    }
}

    // Keyboard shortcuts (A, B, C, D keys)
    document.addEventListener('keydown', (e) => {
        // Navigation
        if (e.key === 'ArrowRight') goNext();
        else if (e.key === 'ArrowLeft') goPrev();
        
        // Option selection (A, B, C, D)
        const key = e.key.toUpperCase();
        if (/^[A-D]$/.test(key)) {
            const optionIndex = key.charCodeAt(0) - 65; // A=0, B=1, C=2, D=3
            
            // Check if option exists for current question
            const currentQuestion = questionsList[currentIndex];
            if (currentQuestion && optionIndex < currentQuestion.options.length) {
                const selectedOptionText = currentQuestion.options[optionIndex];
                answers[currentIndex] = optionIndex;
                userSelections[currentIndex] = selectedOptionText;
                
                updateOptionSelection();
                updateProgressUI();
                
                // Check if correct
                const isCorrect = selectedOptionText.trim().toLowerCase() === 
                                 currentQuestion.correct_answer_text.trim().toLowerCase();
                
                saveAnswer(currentQuestion.question_id, selectedOptionText, isCorrect ? 1 : 0);
            }
        }
    });

    // Initialize quiz
    window.addEventListener('DOMContentLoaded', () => {
        if (total === 0) {
            if (questionBox) questionBox.textContent = "No questions found for this quiz.";
            return;
        }
        
        renderQuestion(0);
        updateProgressUI();
        startTotalTimer();
    });

    // Summary panel buttons
    if (tryAgainBtn) {
        tryAgainBtn.addEventListener("click", () => {
            // Reload the page to restart quiz
            window.location.reload();
        });
    }

    if (closeSummaryBtn) {
        closeSummaryBtn.addEventListener("click", () => {
            // Redirect to dashboard or lessons page
            window.location.href = "../user_dashboard/user-dashboard.php";
        });
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
