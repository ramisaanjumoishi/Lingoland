<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ---------- DB connection (adjust credentials if needed) ----------
$host = "localhost";
$user = "root";
$pass = "";
$db   = "lingoland_db";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("DB Connect Error: " . $conn->connect_error);
}

// ---------- Require logged-in user ----------
if (!isset($_SESSION['user_id'])) {
    // redirect to login (or show placeholder)
    header("Location: login.php");
    exit;
}
$user_id = (int) $_SESSION['user_id'];

// ---------- Fetch user basic info (users table) ----------
$userInfo = [
    'name' => 'Learner',
    'avatar' => null,
    'email' => ''
];

$stmt = $conn->prepare("SELECT id, name, avatar, email FROM users WHERE id = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $userInfo['name'] = $row['name'] ?? $userInfo['name'];
        $userInfo['avatar'] = $row['avatar'] ?? null;
        $userInfo['email'] = $row['email'] ?? '';
    }
    $stmt->close();
}

// ---------- Fetch profile stats from user_profile ----------
$profile = [
    'total_score' => 0,
    'total_lessons' => 0,
    'streak' => 0,
    'badges_earned' => 0,
    'weekly_time_minutes' => 0
];

// NOTE: If your columns have different names, change the SELECT below accordingly.
$stmt = $conn->prepare("SELECT total_score, total_lessons, streak, badges_earned, weekly_time_minutes FROM user_profile WHERE user_id = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $profile['total_score'] = (int)($row['total_score'] ?? 0);
        $profile['total_lessons'] = (int)($row['total_lessons'] ?? 0);
        $profile['streak'] = (int)($row['streak'] ?? 0);
        $profile['badges_earned'] = (int)($row['badges_earned'] ?? 0);
        $profile['weekly_time_minutes'] = (int)($row['weekly_time_minutes'] ?? 0);
    }
    $stmt->close();
}

// ---------- Notifications (latest 5) ----------
$notifications = [];
$stmt = $conn->prepare("SELECT id, title, body, created_at, is_read FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 8");
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $notifications[] = $row;
    }
    $stmt->close();
}
// Fallback demo notifications if none
if (empty($notifications)) {
    $notifications[] = [
        'title' => 'Welcome to Lingoland!',
        'body' => 'Your personalized plan is ready. Start your first lesson.',
        'created_at' => date('Y-m-d H:i:s', strtotime('-2 days')),
        'is_read' => 0
    ];
}

// ---------- Messages (latest 5) - placeholder friendly text if none ----------
$messages = [];
$stmt = $conn->prepare("SELECT id, from_user, body, created_at FROM messages WHERE user_id = ? ORDER BY created_at DESC LIMIT 6");
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $messages[] = $row;
    }
    $stmt->close();
}
if (empty($messages)) {
    $messages[] = [
        'from_user' => 'Lingoland Tutor',
        'body' => 'Hi! Try a 10-minute session today to keep your streak going.',
        'created_at' => date('Y-m-d H:i:s', strtotime('-6 hours'))
    ];
}

// ---------- Recommended courses (fetch a few) ----------
$recommended = [];
$stmt = $conn->prepare("SELECT id, title, difficulty, cover_img, COALESCE(progress_pct,0) AS progress_pct FROM courses ORDER BY id DESC LIMIT 6");
if ($stmt) {
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $recommended[] = $row;
    }
    $stmt->close();
}
// fallback demo courses
if (empty($recommended)) {
    $recommended = [
        ['id'=>1,'title'=>'IELTS Essentials','difficulty'=>'Advanced','cover_img'=>'../img/course_ielts.png','progress_pct'=>0],
        ['id'=>2,'title'=>'Everyday Conversation','difficulty'=>'Beginner','cover_img'=>'../img/course_convo.png','progress_pct'=>20],
        ['id'=>3,'title'=>'Business English Toolkit','difficulty'=>'Intermediate','cover_img'=>'../img/course_business.png','progress_pct'=>10],
    ];
}

// ---------- Leaderboard (top 6 by total_score) ----------
$leaderboard = [];
$stmt = $conn->prepare("
    SELECT u.id, u.name, up.total_score, up.profile_id
    FROM users u
    LEFT JOIN user_profile up ON up.user_id = u.id
    ORDER BY COALESCE(up.total_score,0) DESC
    LIMIT 8
");
if ($stmt) {
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $leaderboard[] = $row;
    }
    $stmt->close();
}
if (empty($leaderboard)) {
    $leaderboard = [
        ['id'=>1,'name'=>'Ayesha','total_score'=>920,'profile_id'=>1],
        ['id'=>2,'name'=>'Omar','total_score'=>880,'profile_id'=>2],
        ['id'=>3,'name'=>'Noah','total_score'=>840,'profile_id'=>3],
    ];
}

// ---------- Dummy series data for charts (weekly progress) ----------
$weeklyLabels = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
// create random-ish or based on weekly_time_minutes
$base = max(5, intval($profile['weekly_time_minutes'] / 7));
$weeklyData = [];
for ($i=0;$i<7;$i++){
    $weeklyData[] = max(0, $base + rand(-5, 10));
}

// ---------- Course distribution (pie) - dummy aggregated counts -----------
$courseTypes = ['Conversation','Grammar','Vocabulary','Exam Prep','Business'];
$courseCounts = [12,8,14,6,10]; // demo - you could aggregate by category in DB

// JSON encode data for JS
$js_weekly_labels = json_encode($weeklyLabels);
$js_weekly_data = json_encode($weeklyData);
$js_course_labels = json_encode($courseTypes);
$js_course_data = json_encode($courseCounts);

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Lingoland — Dashboard</title>

  <!-- Chart.js CDN -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <style>
    /* ---------- Reset & fonts ---------- */
    :root{
      --bg: linear-gradient(135deg,#f5a7d3,#97c0fc);
      --card: #fff;
      --muted:#6b6b6b;
      --accent1:#6a4c93;
      --accent2:#9b59b6;
      --sidebar-grad: linear-gradient(180deg,#7b5fb6,#b89cd6);
      --text:#1f1f1f;
      --glass: rgba(255,255,255,0.06);
      --radius:14px;
    }
    body { margin:0; font-family: 'Poppins', sans-serif; background: var(--bg); color:var(--text); }
    .app { display:flex; min-height:100vh; gap:28px; padding:28px; box-sizing:border-box; }

    /* ---------- Sidebar ---------- */
    .sidebar {
      width:260px;
      border-radius:16px;
      padding:20px;
      box-sizing:border-box;
      background: var(--sidebar-grad);
      color: #fff;
      box-shadow: 0 10px 30px rgba(11,11,12,0.08);
      display:flex;flex-direction:column;gap:18px;
    }
    .brand {
      display:flex;align-items:center;gap:12px;
    }
    .logo-circle {
      width:56px;height:56px;border-radius:12px;
      background: linear-gradient(135deg,var(--accent1),var(--accent2));display:flex;align-items:center;justify-content:center;
      font-weight:700;color:#fff;font-size:22px;box-shadow:0 8px 24px rgba(0,0,0,0.12);
    }
    .brand h3 { margin:0; font-size:18px; letter-spacing: -0.4px; }
    .brand p { margin:0; opacity:0.9; font-size:13px; }

    .nav { display:flex;flex-direction:column; gap:8px; margin-top:6px; }
    .nav a { color: rgba(255,255,255,0.95); text-decoration:none; padding:10px 12px; border-radius:10px; display:flex;align-items:center;gap:10px; font-weight:600; font-size:14px; }
    .nav a.active { background: rgba(255,255,255,0.08); box-shadow: inset 0 1px 0 rgba(255,255,255,0.04); }

    .sidebar .small { font-size:13px; opacity:0.95; color:rgba(255,255,255,0.9) }

    /* ---------- Main content area ---------- */
    .main {
      flex:1;
      display:flex;flex-direction:column;gap:18px;
    }

    .topbar {
      display:flex;align-items:center;justify-content:space-between; gap:12px;
    }
    .topbar-left { display:flex; align-items:center; gap:12px; }
    .search { display:flex; align-items:center; gap:8px; background:var(--card); padding:10px 12px; border-radius:12px; box-shadow: 0 6px 18px rgba(11,11,12,0.03); }
    .search input { border:none; outline:none; font-size:14px; width:280px; }

    .topbar-right { display:flex;align-items:center;gap:12px; }

    /* icon buttons */
    .icon-btn { background:var(--card); padding:8px 10px; border-radius:10px; display:inline-flex; align-items:center; gap:8px; cursor:pointer; box-shadow: 0 6px 18px rgba(11,11,12,0.04); position:relative; }
    .icon-btn .badge { position:absolute; top:-6px; right:-6px; background:#ff5a5f; color:#fff; font-size:11px; padding:4px 6px; border-radius:999px; box-shadow:0 6px 12px rgba(0,0,0,0.08); }

    /* profile */
    .profile {
      display:flex;align-items:center;gap:10px;padding:8px 12px;border-radius:12px;background:var(--card);box-shadow:0 6px 18px rgba(11,11,12,0.04);
    }
    .avatar { width:48px;height:48px;border-radius:12px; background:#eee; display:flex;align-items:center; justify-content:center; font-weight:700; color:#333; }
    .profile .meta { line-height:1; }
    .profile .meta .name { font-weight:700; }
    .profile .meta .role { color:var(--muted); font-size:13px; }

    /* ---------- Top stat cards ---------- */
    .cards { display:grid; grid-template-columns: repeat(4, 1fr); gap:18px; }
    .card {
      background:var(--card); border-radius:14px; padding:16px; box-shadow:0 10px 30px rgba(11,11,12,0.04);
      position:relative; overflow:hidden;
    }
    .stat-title { font-size:13px; color:var(--muted); margin-bottom:8px; display:flex;align-items:center;justify-content:space-between; }
    .stat-value { font-size:22px; font-weight:700; margin-bottom:6px; display:flex;align-items:center;gap:8px; }
    .stat-sub { font-size:13px; color:var(--muted); }

    /* small inline chart placeholder area */
    .mini-chart { height:56px; width:100%; margin-top:10px; }

    /* ---------- middle area layout ---------- */
    .middle { display:grid; grid-template-columns: 2fr 1fr; gap:18px; align-items:start; }
    .courses-grid { display:grid; grid-template-columns: repeat(3, 1fr); gap:12px; }
    .course-card { background: linear-gradient(180deg,#fff,#fff); border-radius:12px; padding:12px; box-shadow: 0 10px 30px rgba(11,11,12,0.04); display:flex;flex-direction:column; gap:10px; cursor:pointer; transition:transform .18s ease, box-shadow .18s ease; }
    .course-card:hover { transform: translateY(-6px); box-shadow: 0 20px 40px rgba(11,11,12,0.08); }
    .course-cover { height:90px; border-radius:8px; background:#f3f3f8; display:flex;align-items:center;justify-content:center; color:#777; font-weight:700; }

    /* progress/pie area */
    .progress-card { padding:16px; border-radius:12px; background:var(--card); box-shadow:0 10px 30px rgba(11,11,12,0.04); }
    .chart-wrap { height:220px; }

    /* leaderboard */
    .leaderboard { background:var(--card); border-radius:12px; padding:12px; box-shadow:0 10px 30px rgba(11,11,12,0.04); }
    .leader-item { display:flex; align-items:center; gap:12px; padding:10px 6px; border-radius:8px; transition: transform .25s, box-shadow .25s; }
    .leader-item:hover { transform: translateY(-4px); box-shadow:0 14px 30px rgba(11,11,12,0.06); }
    .rank { width:40px; height:40px; border-radius:8px; display:flex; align-items:center;justify-content:center; font-weight:700; background:linear-gradient(90deg,var(--accent1),var(--accent2)); color:#fff; }
    .leader-meta { flex:1; }
    .leader-score { font-weight:800; color:var(--accent2); }

    /* footer small cards on right */
    .side-widgets { display:flex; flex-direction:column; gap:12px; }
    .widget { background:var(--card); border-radius:12px; padding:12px; box-shadow:0 10px 30px rgba(11,11,12,0.04); }

    /* small drop panels */
    .panel {
      position:absolute; top:48px; right:0; transform:translateY(6px); background:var(--card); min-width:320px; border-radius:12px; box-shadow:0 20px 50px rgba(11,11,12,0.12); padding:10px; display:none; z-index:60;
    }

    /* settings rotate */
    .settings-btn { transition: transform .5s ease; }
    .settings-btn.rotate { transform: rotate(90deg); }

    /* dark mode support (switch class on body) */
    body.dark {
      --card: #0f0f17;
      --muted: #cfcfe6;
      --text: #f4f4f8;
      background: linear-gradient(135deg,#221834,#1c1830);
    }
    body.dark .card, body.dark .widget, body.dark .panel, body.dark .course-card {
      background: #0f0f17;
      color: var(--text);
      box-shadow: 0 8px 30px rgba(0,0,0,0.6);
    }

    /* responsive */
    @media (max-width:1000px) {
      .cards { grid-template-columns: repeat(2, 1fr); }
      .middle { grid-template-columns: 1fr; }
      .courses-grid { grid-template-columns: repeat(2, 1fr); }
      .sidebar { display:none; }
      .app { padding:16px; }
    }
  </style>
</head>
<body>
  <div class="app">
    <!-- Sidebar -->
    <aside class="sidebar" aria-label="Main navigation">
      <div class="brand">
        <div class="logo-circle">L</div>
        <div>
          <h3>Lingoland</h3>
          <p class="small">Personalized English</p>
        </div>
      </div>

      <nav class="nav" aria-label="Main menu">
        <a href="dashboard.php" class="active">Dashboard</a>
        <a href="lessons.php">Lessons</a>
        <a href="courses.php">Courses</a>
        <a href="practice.php">Practice</a>
        <a href="reports.php">Progress</a>
        <a href="settings.php">Settings</a>
        <a href="logout.php" style="margin-top:12px;color:rgba(255,255,255,0.9)">Sign out</a>
      </nav>

      <div style="margin-top:auto;">
        <div class="small">Quick tips</div>
        <div style="margin-top:8px;font-size:13px;color:rgba(255,255,255,0.95)">Keep a 7-day streak to unlock a bonus lesson!</div>
      </div>
    </aside>

    <!-- Main content -->
    <main class="main" role="main">
      <!-- Topbar -->
      <div class="topbar">
        <div class="topbar-left">
          <div class="search">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M21 21l-4.35-4.35" stroke="#9B59B6" stroke-width="1.6" stroke-linecap="round"/></svg>
            <input placeholder="Search lessons, courses..." aria-label="Search" />
          </div>
        </div>

        <div class="topbar-right">
          <div class="icon-btn" id="notifBtn" title="Notifications" aria-haspopup="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M15 17H9" stroke="#666" stroke-width="1.6" stroke-linecap="round"/></svg>
            <?php if (count(array_filter($notifications, fn($n)=> !$n['is_read'])) > 0): ?>
              <div class="badge"><?= count(array_filter($notifications, fn($n)=> !$n['is_read'])) ?></div>
            <?php endif; ?>
            <div class="panel" id="notifPanel" aria-hidden="true">
              <strong style="display:block;margin-bottom:8px">Notifications</strong>
              <?php foreach ($notifications as $n): ?>
                <div style="padding:8px;border-radius:8px;margin-bottom:6px;background:rgba(0,0,0,0.03)">
                  <div style="font-weight:700"><?= htmlspecialchars($n['title']) ?></div>
                  <div style="font-size:13px;color:var(--muted)"><?= htmlspecialchars($n['body']) ?></div>
                  <div style="font-size:12px;color:var(--muted);margin-top:6px"><?= date('M j, H:i', strtotime($n['created_at'])) ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="icon-btn" id="msgBtn" title="Messages" aria-haspopup="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" stroke="#666" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <div class="panel" id="msgPanel" aria-hidden="true">
              <strong style="display:block;margin-bottom:8px">Messages</strong>
              <?php foreach ($messages as $m): ?>
                <div style="padding:8px;border-radius:8px;margin-bottom:6px;background:rgba(0,0,0,0.03)">
                  <div style="font-weight:700"><?= htmlspecialchars($m['from_user'] ?? 'Tutor') ?></div>
                  <div style="font-size:13px;color:var(--muted)"><?= htmlspecialchars($m['body']) ?></div>
                  <div style="font-size:12px;color:var(--muted);margin-top:6px"><?= date('M j, H:i', strtotime($m['created_at'] ?? date('Y-m-d H:i'))) ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="icon-btn settings-btn" id="settingsBtn" title="Settings">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 15.5A3.5 3.5 0 1 0 12 8.5 3.5 3.5 0 0 0 12 15.5z" stroke="#666" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9.5 19.4 1.65 1.65 0 0 0 7.68 19a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 7.6 11.5 1.65 1.65 0 0 0 8 10.68V10a2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 11.5 7.68 1.65 1.65 0 0 0 12 6.6V6a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 15z" stroke="#666" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </div>

          <div class="profile">
            <div class="avatar">
              <?php if ($userInfo['avatar']): ?>
                <img src="<?= htmlspecialchars($userInfo['avatar']) ?>" alt="Avatar" style="width:100%;height:100%;object-fit:cover;border-radius:10px" />
              <?php else: ?>
                <?= strtoupper(htmlspecialchars(substr($userInfo['name'],0,1))) ?>
              <?php endif; ?>
            </div>
            <div class="meta">
              <div class="name"><?= htmlspecialchars($userInfo['name']) ?></div>
              <div class="role">Learner • <?= htmlspecialchars($userInfo['email'] ?: 'New Member') ?></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Top stat cards -->
      <div class="cards" aria-live="polite">
        <div class="card">
          <div class="stat-title">
            <div>Total Score</div>
            <div class="stat-sub">points</div>
          </div>
          <div class="stat-value"><?= number_format($profile['total_score']) ?></div>
          <div class="stat-sub">Your overall learning score</div>
          <div class="mini-chart">
            <canvas id="chartScore"></canvas>
          </div>
        </div>

        <div class="card">
          <div class="stat-title">
            <div>Total Lessons</div>
            <div class="stat-sub">completed</div>
          </div>
          <div class="stat-value"><?= number_format($profile['total_lessons']) ?></div>
          <div class="stat-sub">Lessons finished so far</div>
          <div class="mini-chart">
            <canvas id="chartLessons"></canvas>
          </div>
        </div>

        <div class="card">
          <div class="stat-title">
            <div>Streak</div>
            <div class="stat-sub">days</div>
          </div>
          <div class="stat-value"><?= number_format($profile['streak']) ?></div>
          <div class="stat-sub">Keep it up — consistency pays!</div>
          <div class="mini-chart">
            <canvas id="chartStreak"></canvas>
          </div>
        </div>

        <div class="card">
          <div class="stat-title">
            <div>Badges</div>
            <div class="stat-sub">earned</div>
          </div>
          <div class="stat-value"><?= number_format($profile['badges_earned']) ?></div>
          <div class="stat-sub">Achievements unlocked</div>
          <div class="mini-chart">
            <canvas id="chartBadges"></canvas>
          </div>
        </div>
      </div>

      <!-- Middle section -->
      <div class="middle">
        <div>
          <h3 style="margin:0 0 12px">Recommended Courses</h3>
          <div class="courses-grid">
            <?php foreach ($recommended as $c): ?>
              <div class="course-card" tabindex="0" role="article">
                <div class="course-cover">
                  <?php if (!empty($c['cover_img'])): ?>
                    <img src="<?= htmlspecialchars($c['cover_img']) ?>" alt="<?= htmlspecialchars($c['title']) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:8px" />
                  <?php else: ?>
                    <?= htmlspecialchars(substr($c['title'],0,1)) ?>
                  <?php endif; ?>
                </div>
                <div style="font-weight:700"><?= htmlspecialchars($c['title']) ?></div>
                <div style="font-size:13px;color:var(--muted)"><?= htmlspecialchars($c['difficulty'] ?? 'Intermediate') ?></div>
                <div style="margin-top:auto;display:flex;align-items:center;justify-content:space-between;">
                  <div style="font-weight:700"><?= ($c['progress_pct'] ?? 0) ?>% progress</div>
                  <div><button class="btn ghost" style="padding:8px 12px;border-radius:8px">Resume</button></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div style="display:flex;flex-direction:column; gap:12px;">
          <div class="progress-card">
            <h4 style="margin:0 0 10px">Weekly Time Spent</h4>
            <div class="chart-wrap"><canvas id="weeklyChart"></canvas></div>
            <div style="display:flex;justify-content:space-between;margin-top:12px;color:var(--muted)">
              <div>Weekly total: <strong><?= intval($profile['weekly_time_minutes']) ?> mins</strong></div>
              <div>Avg/day: <strong><?= ($profile['weekly_time_minutes']? round($profile['weekly_time_minutes']/7): 0) ?> mins</strong></div>
            </div>
          </div>

          <div class="widget">
            <h4 style="margin:0 0 10px">Course Focus</h4>
            <div style="height:200px;"><canvas id="pieChart"></canvas></div>
            <div style="display:flex;justify-content:space-between;margin-top:10px">
              <div style="font-size:13px;color:var(--muted)">Conversation: 30%</div>
              <div style="font-size:13px;color:var(--muted)">Exam Prep: 12%</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Lower section -->
      <div style="display:grid;grid-template-columns:2fr 1fr;gap:18px;align-items:start;">
        <div class="leaderboard">
          <h4 style="margin:0 0 8px">Leaderboard</h4>
          <?php $rank = 1; ?>
          <?php foreach ($leaderboard as $lb): ?>
            <div class="leader-item" style="margin-bottom:8px">
              <div class="rank"><?= $rank ?></div>
              <div class="leader-meta">
                <div style="font-weight:700"><?= htmlspecialchars($lb['name'] ?? 'Member') ?></div>
                <div style="font-size:13px;color:var(--muted)"><?= htmlspecialchars($lb['profile_id'] ? "Profile #".$lb['profile_id'] : "Learner") ?></div>
              </div>
              <div class="leader-score"><?= number_format($lb['total_score'] ?? 0) ?></div>
            </div>
            <?php $rank++; endforeach; ?>
        </div>

        <div style="display:flex;flex-direction:column;gap:12px;">
          <div class="widget">
            <h4 style="margin:0 0 8px">AI Tutor - Quick Tip</h4>
            <p style="margin:0;color:var(--muted)">Write a 2-sentence summary about your last lesson. The AI will correct grammar and suggest stronger vocabulary. Try now to earn bonus points!</p>
          </div>

          <div class="widget">
            <h4 style="margin:0 0 8px">Recent Activity</h4>
            <div style="display:flex;flex-direction:column;gap:8px">
              <div style="font-size:13px;color:var(--muted)">You completed "Everyday Conversation" — 20 mins ago</div>
              <div style="font-size:13px;color:var(--muted)">New badge: "7-day streak" — 2 days ago</div>
              <div style="font-size:13px;color:var(--muted)">Course "IELTS Essentials" recommended — 4 days ago</div>
            </div>
          </div>
        </div>
      </div>

    </main>
  </div>

  <!-- ---------- JS for interactions and charts ---------- -->
  <script>
    // ----------------- Panels toggle behavior -----------------
    function togglePanel(btnId, panelId) {
      const btn = document.getElementById(btnId);
      const panel = document.getElementById(panelId);
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        // hide others
        document.querySelectorAll('.panel').forEach(p => { if (p !== panel) p.style.display = 'none'; });
        panel.style.display = (panel.style.display === 'block') ? 'none' : 'block';
      });
    }
    togglePanel('notifBtn','notifPanel');
    togglePanel('msgBtn','msgPanel');

    // close panels on doc click
    document.addEventListener('click', ()=> { document.querySelectorAll('.panel').forEach(p => p.style.display = 'none'); });

    // settings rotate on click
    const settingsBtn = document.getElementById('settingsBtn');
    settingsBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      settingsBtn.classList.toggle('rotate');
      // small demo: toggling dark mode via settings (for convenience)
      document.body.classList.toggle('dark');
    });

    // ----------------- Small animated mini charts in cards (sparkline look) -----------------
    // We'll create small line charts for the 4 top cards with dynamic tooltips & cursor change.
    const commonOptions = {
      responsive: true,
      maintainAspectRatio: false,
      elements: { point: { radius: 0 } },
      plugins: {
        legend: { display:false },
        tooltip: {
          enabled: true,
          intersect: false,
          backgroundColor: 'rgba(0,0,0,0.8)',
          callbacks: {
            label: (context) => context.parsed.y + (context.dataset.label ? ' ' + context.dataset.label : '')
          },
          // small custom caret (makes it feel like +)
        }
      },
      scales: {
        x: { display: false },
        y: { display: false }
      },
      onHover: function(e, elements) {
        if (elements && elements.length) {
          e.native.target.style.cursor = 'crosshair';
        } else {
          e.native.target.style.cursor = 'default';
        }
      }
    };

    // helper: create sparkline chart
    function createSpark(id, data, color) {
      const ctx = document.getElementById(id).getContext('2d');
      return new Chart(ctx, {
        type: 'line',
        data: {
          labels: Array.from({length: data.length}, (_,i)=>i+1),
          datasets: [{
            label: '',
            data: data,
            borderColor: color,
            borderWidth: 2,
            fill: true,
            backgroundColor: (ctx) => {
              // gradient fill
              const g = ctx.createLinearGradient(0,0,0,80);
              g.addColorStop(0, color);
              g.addColorStop(1, 'rgba(255,255,255,0)');
              return g;
            }
          }]
        },
        options: JSON.parse(JSON.stringify(commonOptions))
      });
    }

    // sample small datasets (could be derived from server-side eventually)
    const scoreData = [<?= implode(',', array_map('intval', array_slice($weeklyData,0,8))) ?>];
    const lessonsData = scoreData.map((v,i)=> Math.max(0, Math.round(v * 0.2 + Math.random()*6)));
    const streakData = scoreData.map((v,i)=> Math.max(0, Math.round(v * 0.1 + Math.random()*3)));
    const badgesData = [0,1,1,2,2,3,3];

    createSpark('chartScore', scoreData, 'rgba(106,76,147,0.95)');
    createSpark('chartLessons', lessonsData, 'rgba(155,89,182,0.95)');
    createSpark('chartStreak', streakData, 'rgba(107,85,154,0.95)');
    createSpark('chartBadges', badgesData, 'rgba(153,102,204,0.95)');

    // ----------------- Weekly chart (larger) -----------------
    const weeklyLabels = <?= $js_weekly_labels ?>;
    const weeklyData = <?= $js_weekly_data ?>;

    const weeklyCtx = document.getElementById('weeklyChart').getContext('2d');
    const weeklyChart = new Chart(weeklyCtx, {
      type: 'line',
      data: {
        labels: weeklyLabels,
        datasets: [{
          label: 'Minutes',
          data: weeklyData,
          borderColor: 'rgba(106,76,147,1)',
          backgroundColor: function(ctx) {
            const g = ctx.createLinearGradient(0,0,0,300);
            g.addColorStop(0, 'rgba(155,89,182,0.28)');
            g.addColorStop(1, 'rgba(255,255,255,0)');
            return g;
          },
          fill: true,
          tension: 0.35,
          pointRadius: 4,
          pointHoverRadius: 6
        }]
      },
      options: {
        responsive:true, maintainAspectRatio:false,
        plugins: {
          legend: { display:false },
          tooltip: {
            enabled:true,
            callbacks: {
              title: (ctx) => 'Day: ' + ctx[0].label,
              label: (ctx) => ctx.parsed.y + ' minutes'
            },
            backgroundColor: 'rgba(0,0,0,0.8)'
          }
        },
        scales: {
          x: {
            grid: { display:false }
          },
          y: {
            grid: { color: 'rgba(0,0,0,0.04)' },
            beginAtZero:true
          }
        }
      }
    });

    // change cursor to plus sign when hovering over chart area
    document.getElementById('weeklyChart').addEventListener('mousemove', (ev) => {
      ev.target.style.cursor = 'crosshair';
    });
    document.getElementById('weeklyChart').addEventListener('mouseleave', (ev) => { ev.target.style.cursor = 'default'; });

    // ----------------- Pie chart -----------------
    const pieCtx = document.getElementById('pieChart').getContext('2d');
    const pie = new Chart(pieCtx, {
      type: 'doughnut',
      data: {
        labels: <?= $js_course_labels ?>,
        datasets: [{
          data: <?= $js_course_data ?>,
          backgroundColor: ['#9B59B6','#6A4C93','#C39BD3','#8E44AD','#DCC6E0']
        }]
      },
      options: {
        responsive:true, maintainAspectRatio:false,
        plugins: { legend: { position:'bottom' } }
      }
    });

    // ----------------- Leaderboard animation (simple loop highlight) -----------------
    (function animateLeaderboard() {
      const items = document.querySelectorAll('.leader-item');
      if (!items.length) return;
      let idx = 0;
      setInterval(() => {
        items.forEach((it,i)=> it.style.opacity = (i===idx? '1':'0.6'));
        idx = (idx+1) % items.length;
      }, 2200);
    })();

    // ----------------- small accessibility / keyboard support -----------------
    document.querySelectorAll('.course-card, .interest').forEach(el => {
      el.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') el.click();
      });
    });
  </script>
</body>
</html>
