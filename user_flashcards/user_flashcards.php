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

/* ---------------------------------------------------
   HELPER FUNCTIONS
----------------------------------------------------*/
function log_user_activity($user_id, $action_type) {
    $servername = "localhost";
    $username = "root";
    $password = "";
    $dbname = "lingoland_db";

    $conn = mysqli_connect($servername, $username, $password, $dbname);

    if (!$conn) {
        die("Connection failed: " . mysqli_connect_error());
    }

    $action_time = date("Y-m-d H:i:s");
    $log_sql = "INSERT INTO activity_log (user_id, action_type, action_time) 
                VALUES (?, ?, ?)";

    $stmt = $conn->prepare($log_sql);
    $stmt->bind_param("iss", $user_id, $action_type, $action_time);
    $stmt->execute();
    $stmt->close();
    mysqli_close($conn);
}

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
    // Log user activity for viewing flashcard
        log_user_activity($user_id, 'flashcard_reviewed');

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
     // Log user activity for toggling reaction
        log_user_activity($user_id, $value == 1 ? 'flashcard_liked' : 'flashcard_unliked');
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
             // Log user activity for bookmarking
            log_user_activity($user_id, 'flashcard_bookmarked');
            echo json_encode(['success' => true, 'bookmarked' => 1]);
            exit();
        } else {
            // delete
            $del = $conn->prepare("DELETE FROM bookmark_flashcard WHERE card_id=? AND user_id=?");
            $del->bind_param("ii", $card_id, $user_id);
            $del->execute();
            $del->close();
             log_user_activity($user_id, 'flashcard_unbookmarked');
            echo json_encode(['success' => true, 'bookmarked' => 0]);
            exit();
        }
    }

    // Unknown action
    echo json_encode(['success' => false, 'error' => 'unknown action']);
    exit();
}

// ------------------ Page GET: fetch flashcards to render ------------------
// Search handling
$search_query = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search'])) {
    $search_query = trim($_POST['search']);
} elseif (isset($_GET['search'])) {
    $search_query = trim($_GET['search']);
}

// Escape for SQL safely
$sq = mysqli_real_escape_string($conn, $search_query);

// Step 1: Base WHERE condition (for search)
$where_flashcard = $search_query !== '' ? "WHERE meaning LIKE '%$sq%' OR card_id LIKE '%$sq%'" : "";
$where_user_flashcard = $search_query !== '' ? "AND (meaning LIKE '%$sq%' OR card_id LIKE '%$sq%')" : "";

// ------------------ Recommendation include & ordering ------------------
// include the recommender helper
include_once __DIR__ . "/../ml_api/recommended_flashcards.php";

// If user is searching, keep simple UNION (search results)
if ($search_query !== '') {
    // --- If user is searching ---
    $sql = "
        SELECT card_id, meaning, word_usage, 'global' AS source
        FROM flashcard
        WHERE meaning LIKE '%$sq%' OR card_id LIKE '%$sq%'

        UNION ALL

        SELECT card_id, meaning, word_usage, 'user' AS source
        FROM user_created_flashcard
        WHERE user_id = $user_id AND profile_id = $profile_id
          AND (meaning LIKE '%$sq%' OR card_id LIKE '%$sq%')

        ORDER BY card_id DESC
    ";
} else {
    // --- No search: use recommender order ---
    $recommended_ids = getRecommendedFlashcards($conn, $user_id, $profile_id);

    if (!empty($recommended_ids)) {
        $id_list = implode(',', array_map('intval', $recommended_ids));
        $sql = "
            SELECT card_id, meaning, word_usage, 'global' AS source
            FROM flashcard
            WHERE card_id IN ($id_list)

            UNION ALL

            SELECT card_id, meaning, word_usage, 'user' AS source
            FROM user_created_flashcard
            WHERE user_id = $user_id AND profile_id = $profile_id
              AND card_id IN ($id_list)

            ORDER BY FIELD(card_id, $id_list)
        ";
    } else {
        // --- fallback: combined but strictly user-specific for user-created ones ---
        $sql = "
            SELECT card_id, meaning, word_usage, 'global' AS source
            FROM flashcard

            UNION ALL

            SELECT card_id, meaning, word_usage, 'user' AS source
            FROM user_created_flashcard
            WHERE user_id = $user_id AND profile_id = $profile_id

            ORDER BY card_id DESC
        ";
    }
}



$result = mysqli_query($conn, $sql);
if (!$result) {
    die("Query error: " . mysqli_error($conn));
}

$flashcards = [];
while ($row = mysqli_fetch_assoc($result)) {
    $flashcards[] = [
        'card_id' => (int)$row['card_id'],
        'meaning' => $row['meaning'],
        'word_usage' => $row['word_usage'] ?? ''
    ];
}

// fetch user's existing reactions/bookmarks to set initial UI state
$user_reactions = [];
$user_bookmarks = [];
if (!empty($flashcards)) {
    $ids = implode(',', array_map(function($f){return (int)$f['card_id'];}, $flashcards));
    // reactions
    $qr = $conn->query("SELECT card_id, reaction FROM user_flashcard WHERE user_id={$user_id} AND card_id IN ({$ids})");
    if ($qr) {
        while ($rr = $qr->fetch_assoc()) {
            $user_reactions[(int)$rr['card_id']] = (int)$rr['reaction'];
        }
    }
    // bookmarks
    $qb = $conn->query("SELECT card_id FROM bookmark_flashcard WHERE user_id={$user_id} AND card_id IN ({$ids})");
    if ($qb) {
        while ($rb = $qb->fetch_assoc()) {
            $user_bookmarks[(int)$rb['card_id']] = 1;
        }
    }
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
    <a href="../courses/courses.php" ><i class="fas fa-book"></i> Courses</a>
    <a href="../leaderboard/view_leaderboard.php"><i class="fas fa-trophy"></i> Leaderboard</a>
    <a id="flashToggle" class = "active"><i class="fas fa-th-large"></i> Flashcards <i class="fas fa-chevron-right"></i></a>
    <div class="sub" id="flashSub">
      <a href="../user_flashcards/user_flashcards.php"  class="active">Review</a>
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
  </div>
</div>

<!-- Header (kept intact) -->
<div class="header">
  <div class="left">
    <button class="icon-btn" id="sidebarToggle"><i class="fas fa-bars"></i></button>
    <h3>Flashcards — Review</h3>
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
<main>
  <h1>Review Your Flashcards 🎴</h1>
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

const writintToggle=document.getElementById('writingToggle');
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
