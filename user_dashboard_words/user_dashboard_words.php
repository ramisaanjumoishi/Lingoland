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

if (!$conn) die("Connection failed: " . mysqli_connect_error());

// Fetch profile and theme
$sql = "SELECT profile_picture FROM user WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) $_SESSION['profile_picture'] = $row['profile_picture'];

$sql = "SELECT theme_id FROM sets WHERE user_id = ? ORDER BY set_on DESC LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$theme_id = 1;
if ($r = $res->fetch_assoc()) $theme_id = $r['theme_id'];

// ============ AJAX ACTION HANDLING ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];
    $word_id = intval($_POST['word_id']);

    // get profile_id (optional)
    $profile_id = null;
    $p = $conn->prepare("SELECT profile_id FROM user_profile WHERE user_id = ? LIMIT 1");
    $p->bind_param("i", $user_id);
    $p->execute();
    $res = $p->get_result();
    if ($row = $res->fetch_assoc()) $profile_id = $row['profile_id'];
    $p->close();

    if ($action === 'toggle_bookmark') {
        $value = intval($_POST['value']);
        if ($value === 1) {
            // insert or ignore
            $q = $conn->prepare("INSERT INTO saves (user_id, word_id, profile_id, saved_on) 
                                 SELECT ?, ?, ?, NOW() FROM DUAL
                                 WHERE NOT EXISTS (SELECT 1 FROM saves WHERE user_id=? AND word_id=?)");
            $q->bind_param("iiiii", $user_id, $word_id, $profile_id, $user_id, $word_id);
            $q->execute();
            echo json_encode(['success' => true, 'bookmarked' => 1]);
        } else {
            $q = $conn->prepare("DELETE FROM saves WHERE user_id=? AND word_id=?");
            $q->bind_param("ii", $user_id, $word_id);
            $q->execute();
            echo json_encode(['success' => true, 'bookmarked' => 0]);
        }
        exit();
    }

    if ($action === 'toggle_reaction') {
    $value = intval($_POST['value']);
    // Check if record exists in word_reaction table
    $check = $conn->prepare("SELECT reaction FROM word_reaction WHERE user_id=? AND word_id=? LIMIT 1");
    $check->bind_param("ii", $user_id, $word_id);
    $check->execute();
    $res = $check->get_result();

    if ($res->num_rows > 0) {
        // Update existing record
        $upd = $conn->prepare("UPDATE word_reaction 
                               SET reaction=?, reacted_on=NOW() 
                               WHERE user_id=? AND word_id=?");
        $upd->bind_param("iii", $value, $user_id, $word_id);
        $upd->execute();
    } else {
        // Insert new reaction record
        $ins = $conn->prepare("INSERT INTO word_reaction (user_id, profile_id, word_id, reaction, reacted_on) 
                               VALUES (?, ?, ?, ?, NOW())");
        $ins->bind_param("iiii", $user_id, $profile_id, $word_id, $value);
        $ins->execute();
    }

    echo json_encode(['success' => true, 'reaction' => $value]);
    exit();
}
}

// ============ FETCH WORDS ============
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$sq = mysqli_real_escape_string($conn, $search_query);
$sql = "SELECT * FROM word WHERE word_text LIKE '%$sq%' OR word_id LIKE '%$sq%'";
$result = mysqli_query($conn, $sql);

// ======================= RECOMMENDATION ENGINE (Rule-based + ML) =======================

// Fetch user's profile
$pstmt = $conn->prepare("SELECT profile_id, learning_goal, target_exam, proficiency_self, personality_type, learning_style FROM user_profile WHERE user_id=? LIMIT 1");
$pstmt->bind_param("i", $user_id);
$pstmt->execute();
$profile = $pstmt->get_result()->fetch_assoc();
$pstmt->close();

$profile_id = $profile['profile_id'] ?? null;

// Fetch interests
$interests = [];
if ($profile_id) {
    $int_q = $conn->prepare("SELECT i.interest_name FROM user_interest ui JOIN interest i ON ui.interest_id=i.interest_id WHERE ui.profile_id=?");
    $int_q->bind_param("i", $profile_id);
    $int_q->execute();
    $int_res = $int_q->get_result();
    while ($r = $int_res->fetch_assoc()) $interests[] = strtolower($r['interest_name']);
    $int_q->close();
}

// Prepare word data
$words = [];
$res_copy = mysqli_query($conn, $sql);
while ($row = mysqli_fetch_assoc($res_copy)) {
    $words[$row['word_id']] = [
        'tags' => strtolower($row['tags'] ?? ''),
        'popularity' => (float)($row['popularity'] ?? 0)
    ];
}

// Rule-based scoring
$rule_scores = [];
foreach ($words as $wid => $w) {
    $score = 0;
    $tags = $w['tags'];

    foreach (['learning_goal','target_exam','proficiency_self','personality_type','learning_style'] as $attr) {
        if (!empty($profile[$attr]) && str_contains($tags, strtolower($profile[$attr]))) {
            $score += 0.2;
        }
    }
    foreach ($interests as $int) {
        if (str_contains($tags, $int)) $score += 0.1;
    }
    $score += 0.3 * ($w['popularity']/100);
    $rule_scores[$wid] = min(1, $score);
}

// Check if user has learning history
$hist_q = $conn->prepare("SELECT COUNT(*) AS c FROM (SELECT word_id FROM saves WHERE user_id=? UNION SELECT word_id FROM word_reaction WHERE user_id=?) t");
$hist_q->bind_param("ii", $user_id, $user_id);
$hist_q->execute();
$hist = $hist_q->get_result()->fetch_assoc();
$has_history = $hist['c'] > 0;
$hist_q->close();

// ML + rule integration
$ml_scores = [];
if ($has_history && $profile_id) {
    $api = "http://127.0.0.1:5002/recommend_words";
    $payload = json_encode(['user_id'=>$user_id, 'profile_id'=>$profile_id]);
    $opts = ['http'=>[
        'method'=>'POST',
        'header'=>"Content-Type: application/json",
        'content'=>$payload,
        'timeout'=>3
    ]];
    $context = stream_context_create($opts);
    $resp = @file_get_contents($api,false,$context);
    if ($resp) {
        $decoded = json_decode($resp,true);
        if (is_array($decoded)) {
            foreach ($decoded as $wid=>$val) $ml_scores[(int)$wid] = (float)$val;
        }
    }
}

$final_scores = [];
foreach ($words as $wid=>$w) {
    $r = $rule_scores[$wid] ?? 0;
    $m = $ml_scores[$wid] ?? 0;
    if ($has_history) $final = 0.4*$r + 0.6*$m;
    else $final = $r;
    $final_scores[$wid] = $final;
}

// Sort words by recommendation descending
arsort($final_scores);

// Reorder result
$ordered_ids = array_keys($final_scores);


$saved_words = [];
$reactions = [];

// 1️⃣ Fetch all saved/bookmarked words
$saved_sql = "SELECT word_id FROM saves WHERE user_id = '$user_id'";
$saved_res = mysqli_query($conn, $saved_sql);
if ($saved_res) {
    while ($r = mysqli_fetch_assoc($saved_res)) {
        $saved_words[$r['word_id']] = true;
    }
}

// 2️⃣ Fetch all reactions from word_reaction table
$react_sql = "SELECT word_id, reaction FROM word_reaction WHERE user_id = '$user_id'";
$react_res = mysqli_query($conn, $react_sql);
if ($react_res) {
    while ($r = mysqli_fetch_assoc($react_res)) {
        $reactions[$r['word_id']] = intval($r['reaction']);
    }
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Lingoland — Vocabulary Study</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

  <style>
    :root {
      --lilac-1: #6a4c93; --lilac-2: #9b59b6;
      --bg-light: #f6f7fb; --card-light: #ffffff; --text-light: #222;
      --bg-dark: #0f1115; --card-dark: #16171b; --text-dark: #e9eefc;
      --gradient: linear-gradient(135deg,#6a4c93,#9b59b6); --muted: #6b6b6b;
        --active-bg: rgba(255,255,255,0.25);
   --active-text: #ffd26f;
    }
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Poppins',sans-serif;background:var(--bg-light);color:var(--text-light);transition:background .3s,color .3s;min-height:100vh}
    body.dark{background:var(--bg-dark);color:var(--text-dark)}

    /* SIDEBAR */
    .sidebar{position:fixed;left:0;top:0;width:240px;height:100vh;background:linear-gradient(180deg,var(--lilac-1),var(--lilac-2));color:#fff;padding:22px 14px;overflow:auto;z-index:60}
    .brand{display:flex;align-items:center;gap:12px;margin-bottom:16px}
    .logo-circle{width:48px;height:48px;border-radius:12px;background:rgba(255,255,255,0.1);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:20px}
    nav.menu{display:flex;flex-direction:column;gap:6px}
    nav.menu a{display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:10px;color:#fff;text-decoration:none;transition:background .2s}
    nav.menu a:hover{background:rgba(255,255,255,0.15)}
    .menu a.active { 
      background:var(--active-bg);
      color:var(--active-text);
      font-weight:700;
      transform:translateX(6px);
    } 
    .sub{display:none;margin-left:18px;flex-direction:column;gap:6px}
    .sub a{font-size:14px;color:rgba(255,255,255,0.9)}
    .sub.show{display:flex;animation:fadeIn .25s ease-in-out}
    @keyframes fadeIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}

    /* HEADER */
    .header{position:fixed;left:240px;right:0;top:0;height:64px;display:flex;align-items:center;justify-content:space-between;padding:10px 20px;background:var(--card-light);border-bottom:1px solid rgba(0,0,0,0.05);z-index:50;transition:background .3s}
    body.dark .header{background:var(--card-dark);border-color:rgba(255,255,255,0.1)}
    .header .right{display:flex;align-items:center;gap:12px;position:relative}
    .icon-btn{background:none;border:0;padding:8px;border-radius:8px;cursor:pointer;font-size:18px}
    #settingsBtn{animation:spin 8s linear infinite}
    @keyframes spin{0%{transform:rotate(0)}100%{transform:rotate(360deg)}}

    /* SETTINGS PANEL */
    .settings-panel{position:fixed;top:0;right:-360px;width:340px;height:100vh;background:var(--card-light);transition:right .35s ease;z-index:90;padding:18px;box-shadow:-14px 0 40px rgba(0,0,0,0.14)}
    .settings-panel.open{right:0}
    .settings-close{position:absolute;top:10px;right:14px;background:none;border:none;font-size:20px;cursor:pointer;color:var(--muted)}
    body.dark .settings-panel{background:var(--card-dark)}

    /* POPUPS */
    .popup{position:absolute;top:60px;right:20px;width:260px;background:var(--card-light);border-radius:10px;box-shadow:0 10px 28px rgba(0,0,0,0.1);padding:10px;display:none;z-index:70}
    body.dark .popup{background:var(--card-dark)}
    .popup.show{display:block;animation:fadeIn .2s ease}

    /* MAIN CONTENT */
    main{margin-left:240px;padding:90px 28px}
    h1{font-size:22px;font-weight:700;margin-bottom:22px}
    .search-bar{display:flex;gap:8px;margin-bottom:18px}
    .search-input{flex:1;padding:10px 14px;border-radius:8px;border:1px solid rgba(0,0,0,0.1)}
    .search-btn{background:var(--gradient);border:0;color:#fff;border-radius:8px;padding:10px 16px;font-weight:600;cursor:pointer}

    /* TABLE */
    table{width:100%;border-collapse:collapse;background:var(--card-light);border-radius:12px;overflow:hidden;box-shadow:0 12px 40px rgba(0,0,0,0.06)}
    body.dark table{background:var(--card-dark)}
    th,td{padding:14px 16px;text-align:center;}
    thead th{background:linear-gradient(135deg,#6a4c93,#9b59b6);color:#fff;font-weight:600}
    tbody tr:nth-child(even){background:rgba(155,89,182,0.05)}
    tbody tr:hover{transform:scale(1.01);background:rgba(155,89,182,0.1);transition:.25s}
    .btn-save{background:var(--lilac-1);color:#fff;padding:6px 12px;border-radius:8px;border:none;font-size:14px;cursor:pointer}
    .btn-save:hover{background:var(--lilac-2)}
    .btn-success{background:var(--gradient);color:#fff;border:none;border-radius:8px;padding:8px 16px;font-weight:600}

    /* CHATBOT */

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
    .chat-window.show{display:block;opacity:1;transform:translateY(0)}

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

    /* HEADER - distinct gradient from yellow to purple */

/* Floating heading animation */
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

/* SEARCH BAR - elegant gradient border and glow */
.search-bar {
  display: flex;
  gap: 10px;
  margin-bottom: 22px;
  animation: fadeIn .8s ease forwards;
}
.search-input {
  flex: 1;
  padding: 12px 16px;
  border-radius: 10px;
  border: 2px solid transparent;
  background: linear-gradient(#fff, #fff) padding-box,
              linear-gradient(90deg, #6a4c93, #9b59b6, #4a90e2) border-box;
  font-size: 15px;
  transition: all .3s ease;
}


body.dark .search-input{
    background: linear-gradient(#16171b, #c0707029) padding-box,
              linear-gradient(90deg, #6a4c93, #9b59b6, #4a90e2) border-box;
    color: #e9eefc;
}
.search-input:focus {
  outline: none;
  box-shadow: 0 0 12px rgba(155, 89, 182, 0.5);
  transform: scale(1.02);
}
.search-btn {
  background: linear-gradient(135deg, #6a4c93, #9b59b6);
  color: #fff;
  padding: 10px 20px;
  border-radius: 10px;
  border: none;
  font-weight: 600;
  letter-spacing: 0.4px;
  cursor: pointer;
  transition: all 0.3s ease;
}
.search-btn:hover {
  background: linear-gradient(135deg, #9b59b6, #ffd26f);
  transform: translateY(-2px);
  box-shadow: 0 8px 22px rgba(0, 0, 0, 0.15);
}

/* BUTTON STYLES - modern gradient hover */
.btn-success {
  background: linear-gradient(135deg, #6a4c93, #9b59b6);
  border: none;
  border-radius: 10px;
  color: #fff;
  font-weight: 600;
  padding: 10px 18px;
  transition: all 0.3s ease;
}
.btn-success:hover {
  background: linear-gradient(135deg, #ffd26f, #9b59b6);
  transform: translateY(-2px);
  box-shadow: 0 8px 22px rgba(0,0,0,0.15);
}

/* TABLE POLISH - distinct rows, shadows, soft transitions */
table {
  border-collapse: collapse;
  width: 100%;
  border-radius: 14px;
  overflow: hidden;
  box-shadow: 0 12px 40px rgba(0, 0, 0, 0.1);
  animation: fadeInSlide .8s ease forwards;
}
thead th {
  background: linear-gradient(135deg, #ffd26f, #9b59b6);
  color: #fff;
  font-weight: 600;
  letter-spacing: 0.3px;
}
tbody tr {
  transition: all 0.35s ease;
}
tbody tr:nth-child(even) {
  background: rgba(155, 89, 182, 0.04);
}
tbody tr:hover {
  transform: scale(1.01);
  background: rgba(155, 89, 182, 0.15);
  box-shadow: 0 4px 18px rgba(0, 0, 0, 0.12);
}

/* Save button enhancement */
.btn-save {
  background: linear-gradient(135deg, #9b59b6, #6a4c93);
  color: #fff;
  border: none;
  padding: 7px 14px;
  border-radius: 8px;
  font-weight: 500;
  cursor: pointer;
  transition: all .3s ease;
}
.btn-save:hover {
  background: linear-gradient(135deg, #ffd26f, #9b59b6);
  transform: translateY(-1px);
  box-shadow: 0 6px 18px rgba(0, 0, 0, 0.15);
}

/* Subtle fade/slide entrance */
@keyframes fadeInSlide {
  from { opacity: 0; transform: translateY(20px); }
  to { opacity: 1; transform: translateY(0); }
}

.btn-icon{background:none;border:none;cursor:pointer;font-size:18px;margin:0 4px;}
.btn-icon.heart.active{color:#ff4b6b;}
.btn-icon.bookmark.active{color:#ffd26f;}
/* ---- Flashcard/Word Action Icon Buttons ---- */
.btn-icon {
  background: none;
  border: none;
  cursor: pointer;
  font-size: 20px;
  color: #fff; /* default white outline */
  transition: all 0.25s ease;
  padding: 6px 10px;
  border-radius: 8px;
  position: relative;
}

/* Slight hover lift and glow */
.btn-icon:hover {
  transform: scale(1.15);
  text-shadow: 0 0 8px rgba(255, 255, 255, 0.6);
  opacity: 0.9;
}

/* Dark mode compatibility */
body.dark .btn-icon {
  color: #fff;
}

/* Light mode compatibility */
body:not(.dark) .btn-icon {
  color: #333;
  filter: drop-shadow(0 0 1px rgba(0,0,0,0.3));
}
body:not(.dark) .btn-icon:hover {
  color: #6a4c93;
  text-shadow: 0 0 6px rgba(106, 76, 147, 0.4);
}

/* Active states */
.btn-icon.heart.active {
  color: #ff4b6b; /* red heart */
  text-shadow: 0 0 10px rgba(255,75,107,0.5);
}

.btn-icon.bookmark.active {
  color: #ffd26f; /* gold bookmark */
  text-shadow: 0 0 10px rgba(255,210,111,0.5);
}

/* Optional subtle click pulse */
.btn-icon:active {
  transform: scale(0.9);
  opacity: 0.8;
}


  </style>
</head>
<body class="<?php echo $theme_id == 2 ? 'dark' : ''; ?>">

  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="brand"><div class="logo-circle">L</div><strong>Lingoland</strong></div>
    <nav class="menu">
      <a href="../user_dashboard/user-dashboard.php"><i class="fas fa-home"></i> Home</a>
      <a href="../courses/courses.php"><i class="fas fa-book"></i> Courses</a>
      <a href="#"><i class="fas fa-pencil-alt"></i> <span>Quiz</span></a>
      <a href="../leaderboard/view_leaderboard.php"><i class="fas fa-trophy"></i> Leaderboard</a>

      <a href="#" id="flashToggle"><i class="fas fa-th-large"></i> Flashcards <i class="fas fa-chevron-right"></i></a>
      <div class="sub" id="flashSub">
        <a href="../user_flashcards/user_flashcards.php">Review Flashcards</a>
        <a href="../user_flashcards/add_flashcards.php">Add New Flashcard</a>
      </div>

      <a href="#" id="vocabToggle"><i class="fas fa-language"></i> Vocabulary <i class="fas fa-chevron-right"></i></a>
      <div class="sub" id="vocabSub">
        <a href="../user_dashboard_words/user_dashboard_words.php">Study New Words</a>
        <a href="../user_dashboard_words/add_words.php">Your Dictionary</a>
      </div>

      <a href="../user_forum_post/user_forum_post.php"><i class="fas fa-comments"></i> Forum</a>
      <a href="#"><i class="fas fa-award"></i> <span>Badge</span></a>
      <a href="../user_certificate/user_certificate.php"><i class="fas fa-certificate"></i> Certificates</a>
      <a href="#"><i class="fas fa-robot"></i> <span>AI Writing Assistant</span></a>
      <a href="../user_dashboard/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </nav>
  </aside>

  <!-- HEADER -->
  <header class="header">
    <h3>Vocabulary Study</h3>
    <div class="right">
      <button id="notifBtn" class="icon-btn">🔔</button>
      <div id="notifPop" class="popup">New lesson available!</div>

      <button id="msgBtn" class="icon-btn">📩</button>
      <div id="msgPop" class="popup">Message from Tutor!</div>

      <button id="settingsBtn" class="icon-btn"><i class="fas fa-cog"></i></button>
      <button id="themeBtn" class="icon-btn">🌓</button>
      <img id="profilePic" src="<?php echo !empty($_SESSION['profile_picture']) ? '../settings/' . $_SESSION['profile_picture'] : '../img/icon9.png'; ?>" alt="Profile" style="width:46px;height:46px;border-radius:10px;cursor:pointer">
      <div id="profileMenu" class="popup">
        <strong><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?></strong>
        <a href="../user_dashboard/logout.php">Logout</a>
      </div>
    </div>
  </header>

  <!-- SETTINGS PANEL -->
  <aside id="settingsPanel" class="settings-panel">
    <button class="settings-close" id="settingsClose"><i class="fas fa-times"></i></button>
    <h3>Settings</h3>
    <p style="color:var(--muted)">Quick preferences</p>
  </aside>

  <!-- MAIN -->
  <main>
    <h1>Study New Words 🧠</h1>

    <div class="search-bar">
      <form method="GET">
        <input type="text" name="search" class="search-input" placeholder="Search words..." value="<?php echo htmlspecialchars($search_query); ?>">
        <button type="submit" class="search-btn"><i class="fas fa-search"></i> Search</button>
      </form>
    </div>

    <div class="table-responsive">
      <table>
    <thead><tr><th>Word</th><th>Definition</th><th>Actions</th></tr></thead>
    <tbody>
      <?php
      if (mysqli_num_rows($result)>0) {
        foreach ($ordered_ids as $wid) {
          $word_q = mysqli_query($conn, "SELECT * FROM word WHERE word_id=$wid");
          $word = mysqli_fetch_assoc($word_q);

          $wid=$word['word_id'];
          $is_saved=isset($saved_words[$wid]);
          $reacted=isset($reactions[$wid]) && $reactions[$wid]==1;
          echo "<tr>
            <td>".htmlspecialchars($word['word_text'])."</td>
            <td>".htmlspecialchars($word['meaning'])."</td>
            <td>
              <button class='btn-icon heart ".($reacted?'active':'')."' data-id='{$wid}' title='React'>
                <i class='".($reacted?'fa-solid':'fa-regular')." fa-heart'></i>
              </button>
              <button class='btn-icon bookmark ".($is_saved?'active':'')."' data-id='{$wid}' title='Bookmark'>
                <i class='".($is_saved?'fa-solid':'fa-regular')." fa-bookmark'></i>
              </button>
            </td>
          </tr>";
        }
      } else echo "<tr><td colspan='3'>No words found!</td></tr>";
      ?>
    </tbody>
  </table>
</main>

  <!-- CHATBOT -->
<div class="chatbot" aria-hidden="false">
  <div class="chat-window" id="chatWindow">
    <div class="chat-header">💬 LingAI — Your Smart Tutor</div>

    <p style="font-size:13px;color:#d0b3ff;margin-bottom:8px;">Hello! Ask anything about English learning 🌟</p>

    <div class="loading-orbs">
      <div></div><div></div><div></div>
    </div>

    <div style="font-size:12px;color:#c8b9e6;margin-bottom:8px;">Try these:</div>
    <div class="chat-example">“Give me 3 idioms for confidence.”</div>
    <div class="chat-example">“Correct this: I goes to school.”</div>
    <div class="chat-example">“Explain present perfect in one line.”</div>

    <div style="margin-top:14px;display:flex;gap:8px;">
      <input id="chatInput" placeholder="Type your question..."
             style="flex:1;padding:8px;border-radius:8px;border:none;
             background:rgba(255,255,255,0.08);color:#fff;margin-top: 30px;">
      <button id="chatSend"
              style="padding:8px 14px;border-radius:8px;background:#b57aff;
              color:#fff;border:0;font-weight:600;margin-top: 30px;">Send</button>
    </div>
  </div>

  <!-- the chatbot floating button -->
  <button class="chatbot-btn" id="chatBtn" title="Open AI Tutor">
    <i class="fas fa-robot"></i>
  </button>

  <script>
    // theme
    const themeBtn=document.getElementById('themeBtn');
    if(localStorage.getItem('lingo_theme')==='dark')document.body.classList.add('dark');
    themeBtn.onclick=()=>{document.body.classList.toggle('dark');localStorage.setItem('lingo_theme',document.body.classList.contains('dark')?'dark':'light');}
    // settings
    document.getElementById('settingsBtn').onclick=()=>document.getElementById('settingsPanel').classList.toggle('open');
    document.getElementById('settingsClose').onclick=()=>document.getElementById('settingsPanel').classList.remove('open');
    // popups
    const notifBtn=document.getElementById('notifBtn'),msgBtn=document.getElementById('msgBtn'),
    notifPop=document.getElementById('notifPop'),msgPop=document.getElementById('msgPop'),
    profilePic=document.getElementById('profilePic'),profileMenu=document.getElementById('profileMenu');
    notifBtn.onclick=()=>{notifPop.classList.toggle('show');msgPop.classList.remove('show');profileMenu.classList.remove('show');}
    msgBtn.onclick=()=>{msgPop.classList.toggle('show');notifPop.classList.remove('show');profileMenu.classList.remove('show');}
    profilePic.onclick=()=>{profileMenu.classList.toggle('show');notifPop.classList.remove('show');msgPop.classList.remove('show');}
    document.addEventListener('click',e=>{
      if(!notifBtn.contains(e.target)&&!notifPop.contains(e.target))notifPop.classList.remove('show');
      if(!msgBtn.contains(e.target)&&!msgPop.contains(e.target))msgPop.classList.remove('show');
      if(!profilePic.contains(e.target)&&!profileMenu.contains(e.target))profileMenu.classList.remove('show');
    });
    // submenu
    document.getElementById('flashToggle').onclick=(e)=>{e.preventDefault();document.getElementById('flashSub').classList.toggle('show');}
    document.getElementById('vocabToggle').onclick=(e)=>{e.preventDefault();document.getElementById('vocabSub').classList.toggle('show');}
    // chatbot
       // --------- CHATBOT ----------
    const chatBtn=document.getElementById('chatBtn');
    const chatWindow=document.getElementById('chatWindow');
    chatBtn.onclick=()=>chatWindow.classList.toggle('show');
    document.getElementById('chatSend').onclick=()=>{
      const q=document.getElementById('chatInput').value.trim();
      if(!q) return alert('Type something!');
      alert('AI Tutor: Try “I have been learning English for three years.”');
    };

    // --- Enhanced chatbot interactions ---
document.querySelectorAll('.chat-example').forEach(e=>{
  e.addEventListener('click',()=>{
    document.getElementById('chatInput').value = e.textContent;
  });
});

// Fake typing effect
const sendBtn = document.getElementById('chatSend');
sendBtn.onclick = ()=>{
  const val = document.getElementById('chatInput').value.trim();
  if(!val) return alert('Type a question!');
  const orbs = document.querySelector('.loading-orbs');
  orbs.style.opacity='1';
  setTimeout(()=>{
    orbs.style.opacity='0';
    alert('LingAI: Practice makes perfect! Keep learning. 🌸');
  },1200);
};

// Smooth entrance animations for main section
window.addEventListener("DOMContentLoaded", () => {
  document.querySelectorAll("main, table, .search-bar").forEach((el, i) => {
    el.style.opacity = "0";
    el.style.transform = "translateY(20px)";
    setTimeout(() => {
      el.style.transition = "all 0.8s ease";
      el.style.opacity = "1";
      el.style.transform = "translateY(0)";
    }, 200 + i * 150);
  });
});

async function toggleAction(id, type){
  const el=document.querySelector(`.${type}[data-id='${id}']`);
  const active=el.classList.contains('active');
  const newVal=active?0:1;
  const form=new FormData();
  form.append('action', type==='bookmark'?'toggle_bookmark':'toggle_reaction');
  form.append('word_id', id);
  form.append('value', newVal);
  const res=await fetch(location.href,{method:'POST',body:form});
  const j=await res.json();
  if(j.success){
    if(newVal===1)el.classList.add('active'); else el.classList.remove('active');
    el.innerHTML=`<i class='${type==='bookmark'?(newVal?'fa-solid fa-bookmark':'fa-regular fa-bookmark'):(newVal?'fa-solid fa-heart':'fa-regular fa-heart')}'></i>`;
  }else alert('Action failed');
}
document.querySelectorAll('.btn-icon.heart').forEach(b=>b.onclick=()=>toggleAction(b.dataset.id,'heart'));
document.querySelectorAll('.btn-icon.bookmark').forEach(b=>b.onclick=()=>toggleAction(b.dataset.id,'bookmark'));

// ---------- misc: header/sidebar/settings/chat behavior (kept similar) ----------
const vocabToggle = document.getElementById('vocabToggle');
const vocabSub = document.getElementById('vocabSub');
if (vocabToggle && vocabSub) {
  vocabToggle.addEventListener('click', ()=> vocabSub.classList.toggle('show'));
  vocabSub.classList.add('show'); // keep visible and Review active
}
  </script>
</body>
</html>
<?php mysqli_close($conn); ?>
