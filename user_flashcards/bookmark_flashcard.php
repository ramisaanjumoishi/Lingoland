<?php
// user_flashcards.php
session_start();

// ------------------ Authentication ------------------
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login/login_process.php");
    exit();
}
$user_id = (int)$_SESSION['user_id'];


// ------------------ DB Connection ------------------
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "lingoland_db";

$conn = mysqli_connect($servername, $username, $password, $dbname);
if (!$conn) {
    http_response_code(500);
    die("DB connection failed: " . mysqli_connect_error());
}

// ------------------ Helper: get profile_id ------------------
$profile_id = null;
$sql_profile = "SELECT profile_id FROM user_profile WHERE user_id = ? LIMIT 1";
$stmt_p = $conn->prepare($sql_profile);
if ($stmt_p) {
    $stmt_p->bind_param("i", $user_id);
    $stmt_p->execute();
    $res_pp = $stmt_p->get_result();
    if ($r = $res_pp->fetch_assoc()) $profile_id = (int)$r['profile_id'];
    $stmt_p->close();
}

// ------------------ Provide AJAX endpoints (same file) ------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];

    // --- 1) View card (insert/update user_flashcard) ---
if ($action === 'view_card' && isset($_POST['card_id'])) {
    $card_id = (int)$_POST['card_id'];

    // fetch word_usage
    $q = $conn->prepare("SELECT word_usage FROM flashcard WHERE card_id = ? LIMIT 1");
    $q->bind_param("i", $card_id);
    $q->execute();
    $r = $q->get_result()->fetch_assoc();
    $word_usage = $r['word_usage'] ?? '';
    $q->close();

    // check existing
    $check = $conn->prepare("SELECT status FROM user_flashcard WHERE card_id=? AND user_id=? AND profile_id=? LIMIT 1");
    $check->bind_param("iii", $card_id, $user_id, $profile_id);
    $check->execute();
    $res = $check->get_result();

    if ($row = $res->fetch_assoc()) {
        $new_status = (int)$row['status'] + 1;
        $upd = $conn->prepare("UPDATE user_flashcard SET status=?, last_reviewed=NOW() WHERE card_id=? AND user_id=? AND profile_id=?");
        $upd->bind_param("iiii", $new_status, $card_id, $user_id, $profile_id);
        $upd->execute();
        $upd->close();
        $status = $new_status;
    } else {
        $ins = $conn->prepare("INSERT INTO user_flashcard (card_id, user_id, profile_id, status, reaction, last_reviewed)
                               VALUES (?, ?, ?, 1, 0, NOW())");
        $ins->bind_param("iii", $card_id, $user_id, $profile_id);
        $ins->execute();
        $ins->close();
        $status = 1;
    }
    $check->close();

    echo json_encode(['success' => true, 'word_usage' => $word_usage, 'status' => $status]);
    exit();
}

    // --- 2) Toggle reaction (heart) ---
    if ($action === 'toggle_reaction' && isset($_POST['card_id']) && isset($_POST['value'])) {
    $card_id = (int)$_POST['card_id'];
    $value = (int)$_POST['value']; // 1 or 0

    $check = $conn->prepare("SELECT 1 FROM user_flashcard WHERE card_id=? AND user_id=? AND profile_id=? LIMIT 1");
    $check->bind_param("iii", $card_id, $user_id, $profile_id);
    $check->execute();
    $res = $check->get_result();

    if ($res && $res->num_rows > 0) {
        $upd = $conn->prepare("UPDATE user_flashcard SET reaction=?, last_reviewed=NOW() WHERE card_id=? AND user_id=? AND profile_id=?");
        $upd->bind_param("iiii", $value, $card_id, $user_id, $profile_id);
        $upd->execute();
        $upd->close();
    } else {
        $ins = $conn->prepare("INSERT INTO user_flashcard (card_id, user_id, profile_id, status, reaction, last_reviewed)
                               VALUES (?, ?, ?, 0, ?, NOW())");
        $ins->bind_param("iiii", $card_id, $user_id, $profile_id, $value);
        $ins->execute();
        $ins->close();
    }
    $check->close();

    echo json_encode(['success' => true, 'reaction' => $value]);
    exit();
}


    // --- 3) Toggle bookmark (bookmark_flashcard table) ---
    if ($action === 'toggle_bookmark' && isset($_POST['card_id']) && isset($_POST['value'])) {
        $card_id = (int)$_POST['card_id'];
        $value = (int)$_POST['value']; // 1 = save, 0 = remove
        if ($value === 1) {
            // insert (avoid duplicates)
            $ins = $conn->prepare("INSERT INTO bookmark_flashcard (card_id, user_id, profile_id, saved_on) 
                                   SELECT ?, ?, ?, NOW() FROM DUAL
                                   WHERE NOT EXISTS (SELECT 1 FROM bookmark_flashcard WHERE card_id=? AND user_id=?) LIMIT 1");
            if ($profile_id === null) {
                $tmp = null;
                $ins->bind_param("iiiii", $card_id, $user_id, $tmp, $card_id, $user_id);
            } else {
                $ins->bind_param("iiiii", $card_id, $user_id, $profile_id, $card_id, $user_id);
            }
            $ins->execute();
            $ins->close();
            echo json_encode(['success' => true, 'bookmarked' => 1]);
            exit();
        } else {
            // delete
            $del = $conn->prepare("DELETE FROM bookmark_flashcard WHERE card_id=? AND user_id=?");
            $del->bind_param("ii", $card_id, $user_id);
            $del->execute();
            $del->close();
            echo json_encode(['success' => true, 'bookmarked' => 0]);
            exit();
        }
    }

    // Unknown action
    echo json_encode(['success' => false, 'error' => 'unknown action']);
    exit();
}

// ------------------ Page GET: fetch only bookmarked flashcards ------------------

// optional search
$search_query = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search'])) {
    $search_query = trim($_POST['search']);
} elseif (isset($_GET['search'])) {
    $search_query = trim($_GET['search']);
}
$sq = mysqli_real_escape_string($conn, $search_query);

// Build main query
$sql = "
(
    SELECT 
        f.card_id,
        f.meaning,
        f.word_usage,
        'global' AS source,
        b.saved_on
    FROM bookmark_flashcard b
    INNER JOIN flashcard f ON f.card_id = b.card_id
    WHERE b.user_id = $user_id
)
UNION ALL
(
    SELECT 
        u.card_id,
        u.meaning,
        u.word_usage,
        'user' AS source,
        b.saved_on
    FROM bookmark_flashcard b
    INNER JOIN user_created_flashcard u 
        ON u.card_id = b.card_id AND u.user_id = b.user_id
    WHERE b.user_id = $user_id
)
ORDER BY saved_on DESC
";


$result = mysqli_query($conn, $sql);
if (!$result) {
    die('Query error: ' . mysqli_error($conn));
}

$flashcards = [];
while ($row = mysqli_fetch_assoc($result)) {
    $flashcards[] = [
        'card_id' => (int)$row['card_id'],
        'meaning' => $row['meaning'],
        'word_usage' => $row['word_usage'] ?? ''
    ];
}

// fetch user’s reactions/bookmarks (to set UI)
$user_reactions = [];
$user_bookmarks = [];
if (!empty($flashcards)) {
    $ids = implode(',', array_map(fn($f) => (int)$f['card_id'], $flashcards));
    // reactions
    $qr = $conn->query("SELECT card_id, reaction FROM user_flashcard WHERE user_id={$user_id} AND card_id IN ({$ids})");
    if ($qr) while ($rr = $qr->fetch_assoc()) $user_reactions[(int)$rr['card_id']] = (int)$rr['reaction'];
    // bookmarks
    $qb = $conn->query("SELECT card_id FROM bookmark_flashcard WHERE user_id={$user_id} AND card_id IN ({$ids})");
    if ($qb) while ($rb = $qb->fetch_assoc()) $user_bookmarks[(int)$rb['card_id']] = 1;
}

// Get user profile picture and theme (reuse your pattern)
$_SESSION['profile_picture'] = $_SESSION['profile_picture'] ?? null;
$theme_id = 1;
$sql = "SELECT profile_picture FROM user WHERE user_id = ? LIMIT 1";
if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($r = $res->fetch_assoc()) $_SESSION['profile_picture'] = $r['profile_picture'];
    $stmt->close();
}
$sql = "SELECT theme_id FROM sets WHERE user_id = ? ORDER BY set_on DESC LIMIT 1";
if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($r = $res->fetch_assoc()) $theme_id = (int)$r['theme_id'];
    $stmt->close();
}

// Prepare JSON of flashcards for JS
$flashcards_json = json_encode($flashcards, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);

// initial states
$reactions_json = json_encode($user_reactions);
$bookmarks_json = json_encode($user_bookmarks);

// ------------------ HTML Output ------------------
?>

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
    <a id="flashToggle" class="active"><i class="fas fa-th-large"></i> Flashcards <i class="fas fa-chevron-right"></i></a>
    <div class="sub show" id="flashSub">
      <a href="../user_flashcards/user_flashcards.php" >Review Flashcards</a>
      <a href="../user_flashcards/create_flashcard.php">Add New</a>
      <a href="../user_flashcards/bookmark_flashcard.php" class="active">Bookmarked Flashcards</a>
    </div>
    <a id="vocabToggle"><i class="fas fa-language"></i> Vocabulary <i class="fas fa-chevron-right"></i></a>
    <div class="sub" id="vocabSub">
      <a href="../user_dashboard_words/user_dashboard_words.php">Study</a>
      <a href="../user_dashboard_words/add_words.php">Your Dictionary</a>
    </div>
    <a href="#"><i class="fas fa-pencil-alt"></i> Quiz</a>
    <a href="#"><i class="fas fa-trophy"></i> Leaderboard</a>
    <a href="#"><i class="fas fa-comments"></i> Forum</a>
    <a href="#"><i class="fas fa-award"></i> Badges</a>
     <a href="#"><i class="fas fa-certificate"></i> <span>Certificates</span></a>
    <a href="#"><i class="fas fa-robot"></i> AI Writing Assistant</a>
    <a href="../user_dashboard/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
  </div>
</div>

<!-- Header (kept intact) -->
<div class="header">
  <div class="left">
    <button class="icon-btn" id="sidebarToggle"><i class="fas fa-bars"></i></button>
    <h3>Flashcards — Bookmarked</h3>
  </div>
  <div class="right">
    <button class="icon-btn" id="notifBtn">🔔<span class="badge" id="notifBadge"></button>
    <button class="icon-btn" id="messageBtn">📩<span class="badge" id="msgBadge"></button>
    <button class="icon-btn" id="settingsBtn"><i class="fas fa-cog"></i></button>
    <span id="darkModeToggle" class="icon-btn">🌓</span>
    <img src="<?php echo !empty($_SESSION['profile_picture']) ? '../settings/' . htmlspecialchars($_SESSION['profile_picture']) : 'https://i.pravatar.cc/40'; ?>" alt="Profile" style="border-radius:50%">
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
  <h1>Study Bookmarked Flashcards🔖</h1>
  <div class="search-bar" style="display:flex;justify-content:center;margin-bottom:12px;">
    <form method="POST" style="display:flex;gap:8px;">
      <input type="text" class= "form-control search-input"name="search" placeholder="Search flashcards..." value="<?php echo htmlspecialchars($search_query); ?>" style="padding:8px 12px;border-radius:10px;border:2px solid #6c63ff;">
      <button class="btn search-btn" style="padding:8px 12px;border-radius:10px;border:none;background:linear-gradient(135deg,#6c63ff,#4e47d1);color:#fff;">Search</button>
    </form>
  </div>

  <div class="flashcard-wrapper">
    <div class="flashcard-column">
      <!-- flashcard container -->
      <div id="flashcard" class="flashcard" role="region" aria-live="polite">
        <div style="position:absolute;right:18px;top:18px;display:flex;gap:8px;align-items:center;">
          <button id="heartBtn" class="btn-icon heart" title="Favorite"><i class="fa-regular fa-heart"></i></button>
          <button id="bookmarkBtn" class="btn-icon bookmark" title="Bookmark"><i class="fa-regular fa-bookmark"></i></button>
        </div>

        <div id="wordText" style="text-align:left">
          <div class="word" id="fc_word">—</div>
          <div class="phonetic" id="fc_phonetic"></div>
          <div class="meaning" id="fc_meaning"></div>
          <div class="usage" id="fc_usage"></div>
        </div>

        <div class="card-controls">
          <button id="viewBtn" class="btn-view">View it</button>
          <div style="flex:1"></div>
          <div style="color:rgba(255,255,255,0.95);font-size:13px;">Viewed: <span id="viewStatus">0</span></div>
        </div>

        <div class="nav-arrows" style="margin-top:10px">
          <button id="prevBtn" title="Previous"><i class="fas fa-chevron-left"></i></button>
          <button id="nextBtn" title="Next"><i class="fas fa-chevron-right"></i></button>
        </div>
      </div>
    </div>

    <div class="side-list" aria-hidden="false">
      <?php if (empty($flashcards)): ?>
        <div class="side-item">No flashcards available</div>
      <?php else: ?>
        <?php foreach ($flashcards as $i => $f): ?>
          <div class="side-item" data-index="<?php echo $i; ?>">
            <div style="flex:1"><?php echo htmlspecialchars($f['meaning']); ?></div>
            <div style="width:28px;text-align:center">
              <i class="fa-regular fa-heart" style="opacity:0.85"></i>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
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

<script>
// ---------- initial data from PHP ----------
const flashcards = <?php echo $flashcards_json ?: '[]'; ?>;
const userReactions = <?php echo $reactions_json ?: '{}'; ?>;
const userBookmarks = <?php echo $bookmarks_json ?: '{}'; ?>;
let currentIndex = 0;

// ---------- UI elements ----------
const wordEl = document.getElementById('fc_word');
const meaningEl = document.getElementById('fc_meaning');
const usageEl = document.getElementById('fc_usage');
const viewBtn = document.getElementById('viewBtn');
const heartBtn = document.getElementById('heartBtn');
const bookmarkBtn = document.getElementById('bookmarkBtn');
const prevBtn = document.getElementById('prevBtn');
const nextBtn = document.getElementById('nextBtn');
const viewStatus = document.getElementById('viewStatus');

// ---------- helper to render current card ----------
function renderCard(index) {
  if (!flashcards || flashcards.length === 0) {
    wordEl.textContent = 'No cards';
    meaningEl.textContent = '';
    usageEl.style.display = 'none';
    viewStatus.textContent = '0';
    return;
  }
  currentIndex = (index + flashcards.length) % flashcards.length;
  const card = flashcards[currentIndex];
  wordEl.textContent = card.meaning || '—';
  // meaning will be same as name in your DB, word_usage only on View
  meaningEl.textContent = ''; // show name only initially (as requested)
  usageEl.textContent = '';
  usageEl.style.display = 'none';
  viewStatus.textContent = '0';

  // set heart/bookmark states from server-known values
  const reacted = !!userReactions[card.card_id];
  const bookmarked = !!userBookmarks[card.card_id];
  setHeartState(reacted, false);
  setBookmarkState(bookmarked, false);

  // set active highlight on side list
  document.querySelectorAll('.side-item').forEach(el => el.classList.remove('active'));
  const side = document.querySelector('.side-item[data-index="'+currentIndex+'"]');
  if (side) {
    side.style.boxShadow = '0 8px 30px rgba(0,0,0,0.08)';
  }
}

// ---------- view (reveal usage) ----------
async function viewCurrent() {
  const card = flashcards[currentIndex];
  if (!card) return;
  try {
    const form = new FormData();
    form.append('action', 'view_card');
    form.append('card_id', String(card.card_id));
    const res = await fetch(location.href, { method: 'POST', body: form });
    const json = await res.json();
    if (json.success) {
      usageEl.textContent = json.word_usage || '(No usage available)';
      usageEl.style.display = 'block';
      viewStatus.textContent = json.status || '1';
    } else {
      alert('Could not mark view.');
    }
  } catch (e) {
    console.error(e);
    alert('Server error while viewing card.');
  }
}

// ---------- toggle reaction ----------
async function toggleReaction() {
  const card = flashcards[currentIndex];
  if (!card) return;
  const currently = !!userReactions[card.card_id];
  const newVal = currently ? 0 : 1;
  try {
    const form = new FormData();
    form.append('action', 'toggle_reaction');
    form.append('card_id', String(card.card_id));
    form.append('value', String(newVal));
    const res = await fetch(location.href, { method: 'POST', body: form });
    const j = await res.json();
    if (j.success) {
      userReactions[card.card_id] = newVal;
      setHeartState(!!newVal, true);
    } else {
      alert('Could not toggle reaction');
    }
  } catch (e) {
    console.error(e);
    alert('Server error while toggling reaction.');
  }
}

// ---------- toggle bookmark ----------
async function toggleBookmark() {
  const card = flashcards[currentIndex];
  if (!card) return;
  const currently = !!userBookmarks[card.card_id];
  const newVal = currently ? 0 : 1;
  try {
    const form = new FormData();
    form.append('action', 'toggle_bookmark');
    form.append('card_id', String(card.card_id));
    form.append('value', String(newVal));
    const res = await fetch(location.href, { method: 'POST', body: form });
    const j = await res.json();
    if (j.success) {
      if (newVal === 1) userBookmarks[card.card_id] = 1;
      else delete userBookmarks[card.card_id];
      setBookmarkState(!!newVal, true);
    } else {
      alert('Could not toggle bookmark');
    }
  } catch (e) {
    console.error(e);
    alert('Server error while toggling bookmark.');
  }
}

// ---------- UI state helpers ----------
function setHeartState(active, animate) {
  if (active) {
    heartBtn.classList.add('active');
    heartBtn.innerHTML = '<i class="fa-solid fa-heart"></i>';
  } else {
    heartBtn.classList.remove('active');
    heartBtn.innerHTML = '<i class="fa-regular fa-heart"></i>';
  }
  if (animate) {
    heartBtn.style.transform = 'scale(1.12)';
    setTimeout(()=>heartBtn.style.transform = '', 160);
  }
}
function setBookmarkState(active, animate) {
  if (active) {
    bookmarkBtn.classList.add('active');
    bookmarkBtn.innerHTML = '<i class="fa-solid fa-bookmark"></i>';
  } else {
    bookmarkBtn.classList.remove('active');
    bookmarkBtn.innerHTML = '<i class="fa-regular fa-bookmark"></i>';
  }
  if (animate) {
    bookmarkBtn.style.transform = 'scale(1.06)';
    setTimeout(()=>bookmarkBtn.style.transform = '', 140);
  }
}

// ---------- navigation ----------
function showPrev() {
  renderCard(currentIndex - 1);
}
function showNext() {
  renderCard(currentIndex + 1);
}

// ---------- side-item click ----------
document.querySelectorAll('.side-item').forEach(el=>{
  el.addEventListener('click', (e)=>{
    const idx = parseInt(el.getAttribute('data-index'), 10);
    renderCard(idx);
  });
});

// ---------- attach events ----------
viewBtn.addEventListener('click', () => viewCurrent());
heartBtn.addEventListener('click', () => toggleReaction());
bookmarkBtn.addEventListener('click', () => toggleBookmark());
prevBtn.addEventListener('click', () => showPrev());
nextBtn.addEventListener('click', () => showNext());

// ---------- initial render ----------
renderCard(0);

// ---------- misc: header/sidebar/settings/chat behavior (kept similar) ----------
const flashToggle = document.getElementById('flashToggle');
const flashSub = document.getElementById('flashSub');
if (flashToggle && flashSub) {
  flashToggle.addEventListener('click', ()=> flashSub.classList.toggle('show'));
  flashSub.classList.add('show'); // keep visible and Review active
}

// sidebar toggle small-screen
const sidebarToggle = document.getElementById('sidebarToggle');
if (sidebarToggle) sidebarToggle.addEventListener('click', ()=> document.body.classList.toggle('sidebar-open'));

// Sidebar submenu toggle
const vocabToggle=document.getElementById('vocabToggle');
vocabToggle.addEventListener('click',()=>document.getElementById('vocabSub').classList.toggle('show'));

// settings panel
const settingsBtn = document.getElementById('settingsBtn');
const settingsPanel = document.getElementById('settingsPanel');
const settingsClose = document.getElementById('settingsClose');
if (settingsBtn) settingsBtn.addEventListener('click', ()=> settingsPanel.classList.add('open'));
if (settingsClose) settingsClose.addEventListener('click', ()=> settingsPanel.classList.remove('open'));

// notif/msg toggles simple
const notifBtn = document.getElementById('notifBtn');
const messageBtn = document.getElementById('messageBtn');
notifBtn && notifBtn.addEventListener('click', ()=> alert('Notifications panel (placeholder)'));
messageBtn && messageBtn.addEventListener('click', ()=> alert('Messages panel (placeholder)'));

// dark mode persistence
const darkToggle = document.getElementById('darkModeToggle');
darkToggle && darkToggle.addEventListener('click', ()=>{
  document.body.classList.toggle('dark');
  localStorage.setItem('darkMode', document.body.classList.contains('dark') ? '1' : '0');
});
if (localStorage.getItem('darkMode') === '1') document.body.classList.add('dark');

// Chatbot
const chatBtn=document.getElementById('chatBtn');
const chatWindow=document.getElementById('chatWindow');
chatBtn.onclick=()=>chatWindow.classList.toggle('show');
document.getElementById('chatSend').onclick=()=>{const q=document.getElementById('chatInput').value.trim();if(!q)return alert('Type something!');alert('LingAI: Keep practicing English confidently 🌸');};

// Animation
window.addEventListener('load',()=>{document.querySelectorAll('.stagger').forEach((el,i)=>{setTimeout(()=>{el.classList.add('enter');},i*120);});});

</script>

</body>
</html>
<?php
// close DB
mysqli_close($conn);
?>
