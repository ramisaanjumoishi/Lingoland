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

// Get profile picture
$sql = "SELECT profile_picture FROM user WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $_SESSION['profile_picture'] = $row['profile_picture'];
}

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

// ----------------- FETCH USER PROFILE DATA -----------------
$sql = "SELECT * FROM user_profile WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();

$profile_id = null;
$sql_profile = "SELECT profile_id FROM user_profile WHERE user_id = ?";
$stmt_p = $conn->prepare($sql_profile);
$stmt_p->bind_param("i", $user_id);
$stmt_p->execute();
$res_p = $stmt_p->get_result();
if ($row_p = $res_p->fetch_assoc()) $profile_id = $row_p['profile_id'];
?>


<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lingoland - Courses</title>
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
  --muted: #c2c0c0;
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
h1{ margin:0; font-size:22px; font-weight:700; }
h2{ margin:0; font-size: 18px; font-weight:600; margin-bottom: 20px; margin-top: 20px;}



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

/* ---------- Writing Evaluation styles (append) ---------- */

:root{
  --we-bg: url('../img/dark1.jpg'); /* replace path with your image path */
  --we-panel-bg-dark: linear-gradient(180deg, rgba(10,8,16,0.6), rgba(20,14,30,0.6));
  --we-card: rgba(255,255,255,0.03);
  --we-glow: rgba(181,122,255,0.16);
}

/* Container and header */
.we-container{ display:flex; justify-content:space-between; align-items:center; gap:18px; margin-bottom:18px; }
.we-left h1{ margin:0; font-size:22px; font-weight:700; color:inherit; }
.we-sub{ margin:6px 0 0 0; color:var(--muted); font-size:13px; }

/* upload + btn */
.we-actions{ display:flex; align-items:center; gap:10px; }
.we-upload{ display:inline-flex; align-items:center; gap:8px; padding:8px 12px; border-radius:10px; background:linear-gradient(90deg,#1b102c,#2b163e); color:#fff; cursor:pointer; border:1px solid rgba(255,255,255,0.04); }
.we-upload input{ display:none; }
.we-upload i{ margin-right:4px; }
.we-btn{ background: linear-gradient(90deg,#4746ff,#7a59ff); color:#fff; border:none; padding:10px 18px; border-radius:10px; cursor:pointer; font-weight:700; box-shadow: 0 8px 28px rgba(74,58,255,0.12); }
.we-btn.hollow{ background:transparent; border:2px solid rgba(181,122,255,0.18); color:inherit; padding:8px 14px; }

/* panel layout */
.we-panel{ display:flex; gap:20px; margin-top:18px; }
.we-main-left{ flex:1 1 66%; display:flex; flex-direction:column; gap:14px; }
.we-right{ width:360px; flex-shrink:0; }

/* text card */
.we-textcard{ background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01)); padding:14px; border-radius:12px; border:1px solid rgba(255,255,255,0.03); box-shadow: 0 8px 30px rgba(11,8,18,0.4); }
#weTextarea{ width:100%; min-height:220px; resize:vertical; padding:12px; font-size:14px; border-radius:10px; border:1px solid rgba(255,255,255,0.04); background: transparent; color:inherit; outline:none; }

/* meta row */
.we-text-meta{ display:flex; justify-content:space-between; align-items:center; margin-top:8px; gap:12px; }
.we-examples button{ background:transparent; border:1px dashed rgba(255,255,255,0.04); color:var(--muted); padding:6px 10px; border-radius:8px; cursor:pointer; font-size:13px; }

/* feedback area */
.we-feedback{ background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01)); padding:14px; border-radius:12px; border:1px solid rgba(255,255,255,0.03); min-height:120px; color:var(--muted); }
.we-summary{ font-size:14px; color:var(--muted); }

/* issues list */
.we-issues{ margin-top:8px; display:flex; flex-direction:column; gap:10px; }
.issue{ background: rgba(255,255,255,0.02); padding:10px; border-radius:10px; border:1px solid rgba(255,255,255,0.03); display:flex; gap:10px; align-items:flex-start; }
.issue .bullet{ width:8px; height:8px; border-radius:50%; margin-top:8px; background:#ff6b6b; flex-shrink:0; }
.issue p{ margin:0; font-size:14px; color:var(--muted); line-height:1.3; }

/* right column scores (gauges) */
.we-scores{ background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.005)); padding:16px; border-radius:12px; border:1px solid rgba(255,255,255,0.03); box-shadow: 0 10px 30px rgba(0,0,0,0.4); }
.score-card{ display:grid; grid-template-columns: repeat(2,1fr); gap:10px; }
.gauge{ display:flex; flex-direction:column; align-items:center; justify-content:center; padding:10px; }
.gauge svg{ width:86px; height:86px; transform:rotate(-90deg); overflow:visible; }
.g-bg{ fill:none; stroke: rgba(255,255,255,0.05); stroke-width:2.5; }
.g-arc{ fill:none; stroke-width:2.8; stroke-linecap:round; transition: stroke-dasharray 1s cubic-bezier(.4,.0,.2,1); }
.g-text{ font-size:6.5px; text-anchor:middle; fill: #0b0b0b; transform:rotate(90deg); font-weight:700; }
 body.dark .g-text{fill: #fff;}
.g-label{ margin-top:8px; font-size:13px; color:var(--muted); font-weight:700; text-align:center; }

/* color theming for arcs */
#g-grammar .g-arc{ stroke: #6fb3ff; }
#g-coherence .g-arc{ stroke: #7a59ff; }
#g-vocab .g-arc{ stroke: #ffd26f; }
#g-overall .g-arc{ stroke: #5efc8d; }

/* overall bigger */
.gauge.overall svg{ width:110px; height:110px; }
.gauge.overall .g-text{ font-size:9px; }

/* dark background image tweak */
body:not(.dark) .we-panel{ background: transparent; }
body.dark .we-panel{
  background-image: var(--we-bg);
  background-repeat: no-repeat;
  background-size: cover;
  border-radius:12px;
  padding: 22px;
  box-shadow: inset 0 0 48px rgba(5,5,10,0.65);
}

/* responsiveness */
@media (max-width: 1000px){
  .we-panel{ flex-direction:column; }
  .we-right{ width:100%; order:2; }
  .we-main-left{ order:1; }
}

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

/* --- FIX: Readability on Light Theme for summary, issues, scores --- */

body:not(.dark) .we-summary,
body:not(.dark) .issue p,
body:not(.dark) .g-label {
    color: #333 !important;
}

body:not(.dark) .issue {
    background: #f5f5f7 !important;
    border: 1px solid #ddd !important;
}

body:not(.dark) .we-feedback {
    background: #ffffff !important;
    border: 1px solid #ddd !important;
    color: #333 !important;
}

body:not(.dark) .we-scores {
    background: #ffffff !important;
    border: 1px solid #e1e1e1 !important;
}

body:not(.dark) .g-bg {
    stroke: #ececec !important;
}

body:not(.dark) .g-text {
    fill: #333 !important;
}

body:not(.dark) .issue:hover {
    background: #ebebef !important; /* keep hover effect but visible */
}
/* Improve Writing button improved */
.we-btn.hollow {
    background: linear-gradient(90deg,#b57aff,#9b59b6);
    border: none;
    color: #fff !important;
    padding: 10px 18px;
    border-radius: 10px;
    cursor: pointer;
    font-weight: 700;
    box-shadow: 0 8px 28px rgba(155,89,182,0.20);
    transition: 0.25s ease;
}

.we-btn.hollow:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 32px rgba(155,89,182,0.30);
}

/* Fade-in animation for whole Writing Evaluation panel */
.we-panel, .we-container {
    opacity: 0;
    transform: translateY(12px);
    transition: opacity .5s ease, transform .5s ease;
}

.we-loaded .we-panel,
.we-loaded .we-container {
    opacity: 1;
    transform: translateY(0);
}


</style>
</head>

<body class="<?php echo ($theme_id == 2) ? 'dark' : ''; ?>">

<!-- Sidebar -->
<div class="sidebar">
  <div class="brand">
    <div class="logo-circle">L</div>
    <h3>Lingoland</h3>
  </div>
  <div class="menu">
    <a href="../user_dashboard/user-dashboard.php"><i class="fas fa-home"></i> Home</a>
    <a href="#" ><i class="fas fa-book"></i> Courses</a>
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
    <a id="writingToggle" class="active"><i class="fas fa-robot"></i> AI Writing Assistant<i class="fas fa-chevron-right"></i></a>
    <div class="sub show" id="writingSub">
      <a href="#">Evaluate Writing</a>
      <a href="#">My Writings</a>
    </div>
    <a href="../user_dashboard/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
  </div>
</div>

<!-- Header -->
<div class="header">
  <div class="left">
    <button class="icon-btn" id="sidebarToggle"><i class="fas fa-bars"></i></button>
    <h3>Evaluate your Writing</h3>
  </div>
  <div class="right">
    <button class="icon-btn" id="notifBtn">🔔<span class="badge" id="notifBadge">5</span></button>
    <div id="notifPop" class="popup" style="display:none;position:absolute;top:64px;right:100px;background:var(--card-light);padding:10px;border-radius:10px;box-shadow:0 8px 20px rgba(0,0,0,0.1);">New course added!</div>
    <button class="icon-btn" id="messageBtn">📩<span class="badge" id="msgBadge">3</span></button>
    <div id="msgPop" class="popup" style="display:none;position:absolute;top:64px;right:60px;background:var(--card-light);padding:10px;border-radius:10px;box-shadow:0 8px 20px rgba(0,0,0,0.1);">You have 2 new messages</div>
    <button class="icon-btn" id="settingsBtn"><i class="fas fa-cog"></i></button>
    <span id="darkModeToggle" class="icon-btn">🌓</span>
    <img src="<?php echo !empty($_SESSION['profile_picture']) ? '../settings/' . $_SESSION['profile_picture'] : 'https://i.pravatar.cc/40'; ?>" alt="Profile" style="border-radius:50%">
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

<main>
  <!-- Writing Evaluation Header -->
  <div class="we-container">
    <div class="we-left">
      <h1>AI Writing Assistant</h1>
      <p class="we-sub">Paste your text or upload a document to evaluate grammar, coherence & vocabulary.</p>
    </div>

    <div class="we-actions">
      <label class="we-upload">
        <input id="weDocInput" type="file" accept=".txt,.doc,.docx,.pdf" />
        <span id="uploadLabel"><i class="fas fa-upload"></i> Upload Document</span>
      </label>
      <button id="weEvaluateBtn" class="we-btn">Evaluate It</button>
    </div>
  </div>

  <!-- Main panel -->
  <section class="we-panel">
    <div class="we-main-left">
      <div class="we-textcard">
        <textarea id="weTextarea" placeholder="Paste your writing here..."></textarea>
        <div class="we-text-meta">
          <span id="weWordCount">0 words</span>
          <div class="we-examples">
          </div>
        </div>
      </div>

      <div class="we-feedback" id="weFeedback">
        <h3>AI Feedback Summary</h3>
        <div id="weSummary" class="we-summary">No evaluation yet — click <strong>Evaluate It</strong>.</div>

        <h4 style="margin-top:18px">Issues found</h4>
        <div id="weIssues" class="we-issues">
          <!-- dynamic list of sentence issues -->
        </div>
      </div>
    </div>

    <aside class="we-right">
      <div class="we-scores">
        <div class="score-card">
          <div class="gauge" data-percent="78" id="g-grammar">
            <svg viewBox="0 0 36 36">
              <path class="g-bg" d="M18 2.0845a15.9155 15.9155 0 1 1 0 31.831a15.9155 15.9155 0 1 1 0-31.831"/>
              <path class="g-arc" stroke-dasharray="0,100" d="M18 2.0845a15.9155 15.9155 0 1 1 0 31.831a15.9155 15.9155 0 1 1 0-31.831"/>
              <text x="18" y="20.35" class="g-text">0</text>
            </svg>
            <div class="g-label">Grammar</div>
          </div>

          <div class="gauge" data-percent="85" id="g-coherence">
            <svg viewBox="0 0 36 36">
              <path class="g-bg" d="M18 2.0845a15.9155 15.9155 0 1 1 0 31.831a15.9155 15.9155 0 1 1 0-31.831"/>
              <path class="g-arc" stroke-dasharray="0,100" d="M18 2.0845a15.9155 15.9155 0 1 1 0 31.831a15.9155 15.9155 0 1 1 0-31.831"/>
              <text x="18" y="20.35" class="g-text">0</text>
            </svg>
            <div class="g-label">Coherence</div>
          </div>

          <div class="gauge" data-percent="70" id="g-vocab">
            <svg viewBox="0 0 36 36">
              <path class="g-bg" d="M18 2.0845a15.9155 15.9155 0 1 1 0 31.831a15.9155 15.9155 0 1 1 0-31.831"/>
              <path class="g-arc" stroke-dasharray="0,100" d="M18 2.0845a15.9155 15.9155 0 1 1 0 31.831a15.9155 15.9155 0 1 1 0-31.831"/>
              <text x="18" y="20.35" class="g-text">0</text>
            </svg>
            <div class="g-label">Vocabulary</div>
          </div>

          <div class="gauge overall" data-percent="79" id="g-overall">
            <svg viewBox="0 0 36 36">
              <path class="g-bg" d="M18 2.0845a15.9155 15.9155 0 1 1 0 31.831a15.9155 15.9155 0 1 1 0-31.831"/>
              <path class="g-arc" stroke-dasharray="0,100" d="M18 2.0845a15.9155 15.9155 0 1 1 0 31.831a15.9155 15.9155 0 1 1 0-31.831"/>
              <text x="18" y="20.35" class="g-text">0</text>
            </svg>
            <div class="g-label">Overall</div>
          </div>
        </div>
        <div style="margin-top:16px; text-align:center;">
          <button id="weImproveBtn" class="we-btn hollow">Improve Your Writing</button>
        </div>
      </div>
    </aside>
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
console.log("JS Loaded ✓");

// Sidebar submenu toggle
const flashToggle = document.getElementById('flashToggle');
const vocabToggle = document.getElementById('vocabToggle');
const writingToggle = document.getElementById('writingToggle'); // FIXED TYPO: was 'writintToggle'

flashToggle.addEventListener('click', () => document.getElementById('flashSub').classList.toggle('show'));
vocabToggle.addEventListener('click', () => document.getElementById('vocabSub').classList.toggle('show'));
writingToggle.addEventListener('click', () => document.getElementById('writingSub').classList.toggle('show'));

// Popup toggles
const notifBtn = document.getElementById('notifBtn');
const msgBtn = document.getElementById('messageBtn');
notifBtn.onclick = () => {
    document.getElementById('notifPop').style.display = document.getElementById('notifPop').style.display === 'block' ? 'none' : 'block';
    document.getElementById('msgPop').style.display = 'none';
};
msgBtn.onclick = () => {
    document.getElementById('msgPop').style.display = document.getElementById('msgPop').style.display === 'block' ? 'none' : 'block';
    document.getElementById('notifPop').style.display = 'none';
};

// Settings panel
const settingsBtn = document.getElementById('settingsBtn');
const settingsPanel = document.getElementById('settingsPanel');
const settingsClose = document.getElementById('settingsClose');
settingsBtn.onclick = () => settingsPanel.classList.add('open');
settingsClose.onclick = () => settingsPanel.classList.remove('open');

// Dark mode toggle
const darkToggle = document.getElementById('darkModeToggle');
darkToggle.addEventListener('click', () => {
    document.body.classList.toggle('dark');
    localStorage.setItem('darkMode', document.body.classList.contains('dark'));
});
if (localStorage.getItem('darkMode') === 'true') document.body.classList.add('dark');

// Chatbot
const chatBtn = document.getElementById('chatBtn');
const chatWindow = document.getElementById('chatWindow');
chatBtn.onclick = () => chatWindow.classList.toggle('show');

// Animation
window.addEventListener('load', () => {
    document.querySelectorAll('.stagger').forEach((el, i) => {
        setTimeout(() => {
            el.classList.add('enter');
        }, i * 120);
    });
});

// -------- SETTINGS PANEL BEHAVIOR --------
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

// Editable fields toggle
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

/* ---------- Writing Evaluation JS ---------- */
(function(){
    // elements
    const ta = document.getElementById('weTextarea');
    const wc = document.getElementById('weWordCount');
    const evalBtn = document.getElementById('weEvaluateBtn');
    const issuesBox = document.getElementById('weIssues');
    const summaryBox = document.getElementById('weSummary');
    const improveBtn = document.getElementById('weImproveBtn');
    const fileInput = document.getElementById('weDocInput');
    const uploadLabel = document.getElementById('uploadLabel');

    // Check if required elements exist
    if (!ta || !evalBtn) {
        console.error('Required elements not found');
        return;
    }

    // Helper: animate gauge
    function animateGauge(gaugeEl, percent){
        const arc = gaugeEl.querySelector('.g-arc');
        const text = gaugeEl.querySelector('.g-text');
        const dash = percent + ',100';
        
        // Reset first
        arc.setAttribute('stroke-dasharray', '0,100');
        
        // Animate after delay
        setTimeout(() => {
            arc.setAttribute('stroke-dasharray', dash);
            
            // Animate number counter
            let start = 0;
            const duration = 900;
            const step = 20;
            const rounds = Math.ceil(duration/step);
            const inc = percent/rounds;
            
            const counter = setInterval(() => {
                start = Math.min(percent, start + inc);
                if (text) text.textContent = Math.round(start);
                if (start >= percent - 0.5) clearInterval(counter);
            }, step);
        }, 60);
    }

    // Update word count
    function updateWordCount(){
        const v = ta.value.trim();
        if (!v) { 
            if (wc) wc.textContent = '0 words'; 
            return; 
        }
        const words = v.split(/\s+/).filter(Boolean);
        if (wc) wc.textContent = `${words.length} words`;
    }

    // Initialize word count
    if (ta && wc) {
        ta.addEventListener('input', updateWordCount);
        updateWordCount();
    }

    // Reset gauges to zero
    function resetGaugesToZero(){
        document.querySelectorAll('.gauge').forEach(g => {
            const arc = g.querySelector('.g-arc');
            const txt = g.querySelector('.g-text');
            if (arc) arc.setAttribute('stroke-dasharray','0,100');
            if (txt) txt.textContent = '0';
        });
    }

    // File upload handler
    if (fileInput && uploadLabel) {
        fileInput.addEventListener('change', async (ev) => {
            const file = ev.target.files[0];
            if (!file) return;

            // Update upload label
            uploadLabel.innerHTML = `<i class="fas fa-file"></i> ${file.name}`;

            const name = file.name.toLowerCase();
            const ext = name.split('.').pop() || '';

            try {
                if (ext === 'txt' || file.type === 'text/plain') {
                    const txt = await file.text();
                    ta.value = txt;
                    updateWordCount();
                } 
                else if (ext === 'pdf' || file.type === 'application/pdf') {
                    if (window.pdfjsLib) {
                        const arrayBuffer = await file.arrayBuffer();
                        const pdf = await pdfjsLib.getDocument({data: arrayBuffer}).promise;
                        let fullText = "";
                        for (let i = 1; i <= pdf.numPages; i++) {
                            const page = await pdf.getPage(i);
                            const content = await page.getTextContent();
                            fullText += content.items.map(item => item.str).join(" ") + "\n";
                        }
                        ta.value = fullText;
                        updateWordCount();
                    } else {
                        alert('PDF extraction requires PDF.js library');
                    }
                }
                else if (ext === 'docx') {
                    if (window.JSZip) {
                        const arrayBuffer = await file.arrayBuffer();
                        const zip = await JSZip.loadAsync(arrayBuffer);
                        const docXml = await zip.file("word/document.xml").async("text");
                        const cleaned = docXml.replace(/<[^>]+>/g, " ").replace(/\s+/g, ' ').trim();
                        ta.value = cleaned;
                        updateWordCount();
                    } else {
                        alert('DOCX extraction requires JSZip library');
                    }
                }
                else {
                    alert('File type not supported. Please paste text manually.');
                }
            } catch (error) {
                console.error('File processing error:', error);
                alert('Error processing file. Please paste text manually.');
            }
        });
    }

    // Evaluate button handler
    // Evaluate button handler - UPDATED VERSION
evalBtn.addEventListener('click', async () => {
    const text = ta.value.trim();
    if (!text) {
        if (summaryBox) summaryBox.textContent = "Please paste or upload some text to evaluate.";
        return;
    }

    // UI state
    evalBtn.disabled = true;
    evalBtn.textContent = 'Evaluating...';
    resetGaugesToZero();
    
    if (summaryBox) summaryBox.textContent = 'Contacting AI service — please wait...';
    if (issuesBox) issuesBox.innerHTML = '';

    try {
        console.log('📝 Sending text to Python API...');
        
        // Call Python API with timeout
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 100000); // 30 second timeout

        const pyRes = await fetch('http://127.0.0.1:5002/evaluate_writing', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ 
                profile_id: <?php echo intval($profile_id ?? 0); ?>, 
                text: text 
            }),
            signal: controller.signal
        });

        clearTimeout(timeoutId);

        console.log('📨 API Response status:', pyRes.status);
        
        if (!pyRes.ok) {
            throw new Error(`HTTP error! status: ${pyRes.status}`);
        }

        const ai = await pyRes.json();
        console.log('🤖 AI Response:', ai);

        // Check if we got an error response
        if (ai.error) {
            throw new Error(ai.error);
        }

        // Extract scores with fallbacks
        const grammar = parseInt(ai.grammar_score || 75);
        const coherence = parseInt(ai.coherence_score || 70);
        const vocab = parseInt(ai.vocabulary_score || 65);
        const overall = parseInt(ai.overall_score || 70);

        console.log(`📊 Scores - Grammar: ${grammar}, Coherence: ${coherence}, Vocab: ${vocab}, Overall: ${overall}`);

        // Animate gauges
        animateGauge(document.getElementById('g-grammar'), grammar);
        animateGauge(document.getElementById('g-coherence'), coherence);
        animateGauge(document.getElementById('g-vocab'), vocab);
        animateGauge(document.getElementById('g-overall'), overall);

        // Update summary
        if (summaryBox) {
            summaryBox.innerHTML = `<strong>Summary:</strong> ${ai.ai_feedback_summary || 'Evaluation completed successfully.'}`;
        }

        // Update issues
        if (issuesBox) {
            issuesBox.innerHTML = '';
            const suggestions = Array.isArray(ai.suggestions) ? ai.suggestions : [];
            
            if (suggestions.length > 0) {
                suggestions.slice(0, 8).forEach(s => {
                    const el = document.createElement('div');
                    el.className = 'issue';
                    el.innerHTML = `<div class="bullet"></div><div><p>${s}</p></div>`;
                    issuesBox.appendChild(el);
                });
            } else {
                const el = document.createElement('div');
                el.className = 'issue';
                el.innerHTML = `<div class="bullet" style="background:#5efc8d"></div><div><p><strong>Great job!</strong> No major issues detected in your writing.</p></div>`;
                issuesBox.appendChild(el);
            }
        }

        // Save to database
        try {
            const saveRes = await fetch('writing_eval_save.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    profile_id: <?php echo intval($profile_id ?? 0); ?>,
                    text: text,
                    ai_result: ai
                })
            });
            
            if (saveRes.ok) {
                const saveJson = await saveRes.json();
                console.log('💾 Saved evaluation to database:', saveJson);
            }
        } catch (saveErr) {
            console.warn('Could not save evaluation:', saveErr);
        }

    } catch (err) {
        console.error('❌ Evaluation error:', err);
        if (summaryBox) {
            if (err.name === 'AbortError') {
                summaryBox.textContent = 'Request timeout. AI service is taking too long to respond.';
            } else {
                summaryBox.textContent = `Error: ${err.message}. Check console for details.`;
            }
        }
        
        // Show fallback scores
        animateGauge(document.getElementById('g-grammar'), 60);
        animateGauge(document.getElementById('g-coherence'), 60);
        animateGauge(document.getElementById('g-vocab'), 60);
        animateGauge(document.getElementById('g-overall'), 60);
        
    } finally {
        evalBtn.disabled = false;
        evalBtn.textContent = 'Evaluate It';
    }
});
    // Improve button handler
    if (improveBtn) {
        improveBtn.addEventListener('click', () => {
            // Just enable editing, don't reset scores
            ta.disabled = false;
            ta.style.opacity = "1";
            
            // Visual feedback
            improveBtn.classList.add('pulse');
            setTimeout(() => improveBtn.classList.remove('pulse'), 900);
        });
    }

})();

// Page load animation
window.addEventListener("load", () => {
    document.body.classList.add("we-loaded");
});
</script>

<!-- pdf.js (worker-free simple build) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.6.172/pdf.min.js"></script>
<script>pdfjsLib = window['pdfjsLib'] || window.pdfjsLib; pdfjsLib.GlobalWorkerOptions && (pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.6.172/pdf.worker.min.js');</script>

<!-- JSZip for simple docx extraction -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>


</body>
</html>
