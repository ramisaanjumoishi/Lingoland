<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login_signup/login_signup.html");
    exit();
}

$user_id = $_SESSION['user_id'];

$conn = new mysqli("localhost", "root", "", "lingoland_db");

if ($conn->connect_error) {
    die("DB Connection failed: " . $conn->connect_error);
}

// Fetch last saved theme from sets table
$sql = "SELECT theme_id FROM sets WHERE user_id = ? ORDER BY set_on DESC LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();

$theme_id = $row ? $row['theme_id'] : 1; // default light
$body_class = ($theme_id == 2) ? "dark" : "";

?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Lingoland — Personalize your learning</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&family=Roboto:wght@300;400;500&display=swap" rel="stylesheet">
  <style>
    /* CSS Variables for theme */
    :root{
      --bg-1: #f5a7d3;
      --bg-2: #97c0fc;
      --accent-1: #6a4c93; /* Lingoland purple */
      --accent-2: #9b59b6;
      --muted: #6b6b6b;
      --card: #ffffff;
      --glass: rgba(255,255,255,0.45);
      --radius: 14px;
      --shadow: 0 10px 30px rgba(18,18,18,0.08);
      --text: #1f1f1f;
    }

    /* Dark theme */
    body.dark {
      --bg-1: #2a2540;
      --bg-2: #2e2b3f;
      --accent-1: #b89cd6;
      --accent-2: #8e6bb8;
      --muted: #cfcfe6;
      --card: #12121a;
      --glass: rgba(255,255,255,0.03);
      --shadow: 0 8px 24px rgba(0,0,0,0.6);
      --text: #f4f4f8;
    }

    html,body{height:100%;margin:0;font-family:'Poppins',system-ui,Roboto,Arial,sans-serif;background:linear-gradient(135deg,var(--bg-1),var(--bg-2));-webkit-font-smoothing:antialiased; -moz-osx-font-smoothing:grayscale; color:var(--text)}
    .wrap {
      min-height:100%;
      display:flex;
      align-items:center;
      justify-content:center;
      padding:36px;
      box-sizing:border-box;
    }

    /* Stage container */
    .stage {
      width:100%;
      max-width:1100px;
      display:grid;
      grid-template-columns: 460px 1fr;
      gap:28px;
      align-items:center;
    }

    /* Left card (primary) */
    .card {
      background: linear-gradient(180deg, rgba(255,255,255,0.06), rgba(255,255,255,0.02));
      border-radius:var(--radius);
      padding:28px;
      box-shadow:var(--shadow);
      backdrop-filter: blur(6px);
      color:var(--text);
      overflow:hidden;
      position:relative;
      border: 1px solid rgba(255,255,255,0.06);
    }

    .brand {
      display:flex;
      gap:12px;
      align-items:center;
      margin-bottom:18px;
    }
    .logo-circle{
      width:56px;height:56px;border-radius:12px;
      background:linear-gradient(135deg,var(--accent-1),var(--accent-2));
      display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:20px;box-shadow:0 6px 18px rgba(0,0,0,0.18)
    }
    .brand h5{margin:0;font-size:13px;font-weight:600;color:rgba(255,255,255,0.95)}
    .brand p{margin:0;font-size:12px;color:var(--muted)}

    .hero {
      margin-top:8px;
      padding:12px;
      border-radius:10px;
      background: linear-gradient(120deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));
    }

    .title {
      font-size:28px;
      line-height:1.02;
      margin:16px 0 8px;
      font-weight:700;
      color:var(--text);
      letter-spacing:-0.2px;
    }
    .subtitle {
      margin:0 0 18px;color:var(--muted); font-size:15px; line-height:1.4;
    }

    .cta-row {display:flex; gap:12px; align-items:center}
    .btn {
      padding:12px 18px;
      border-radius:12px;
      border:0;
      font-weight:600;
      cursor:pointer;
      display:inline-flex;
      align-items:center;
      gap:10px;
      transition: transform .12s ease, box-shadow .12s ease, opacity .12s;
      box-shadow: 0 8px 20px rgba(106,76,147,0.12);
    }
    .btn.primary {
      background: linear-gradient(90deg,var(--accent-1),var(--accent-2));
      color:white;
      font-size:16px;
    }
    .btn.ghost {
      background: transparent;
      color:var(--text);
      border:1px solid rgba(0,0,0,0.06);
    }
    .btn:active{transform:translateY(1px)}
    .muted { color:var(--muted); font-size:13px }

    /* progress bar top */
    .progress-top {
      display:flex;
      align-items:center;
      gap:12px;
      margin-top:18px;
      margin-bottom:6px;
    }
    .dots {display:flex; gap:6px}
    .dot {width:10px;height:10px;border-radius:6px;background:rgba(255,255,255,0.12)}
    .dot.active {width:28px;background:linear-gradient(90deg,var(--accent-1),var(--accent-2));box-shadow:0 6px 14px rgba(105,64,140,0.18)}

    /* right pane preview */
    .preview {
      height:420px;
      border-radius:var(--radius);
      padding:18px;
      display:flex;
      flex-direction:column;
      gap:12px;
      align-items:stretch;
      justify-content:flex-start;
      background: linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.01));
      border:1px solid rgba(255,255,255,0.04);
      box-shadow:var(--shadow);
    }
    .preview h4 {margin:6px 0 0;font-size:16px;color:var(--text)}
    .preview p {margin:0;color:var(--muted);font-size:14px}
    .preview .tile {
      background:var(--card);
      border-radius:10px;
      padding:12px;
      display:flex;gap:12px;align-items:center;
      box-shadow: 0 6px 18px rgba(11,11,12,0.06);
      transition:transform .22s ease, box-shadow .22s ease;
    }
    .preview .tile:hover{transform:translateY(-6px);box-shadow:0 14px 30px rgba(11,11,12,0.08)}
    .tile .thumb {
      width:60px;height:60px;border-radius:8px;background:linear-gradient(90deg,var(--accent-2),var(--accent-1));color:white;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:700;
    }
    .tile .meta {font-size:14px;color:var(--text); font-weight:600}
    .tile .sub {font-size:13px;color:var(--muted);font-weight:500}

    /* small utilities */
    .theme-toggle {position:absolute; top:18px; right:18px; border-radius:999px; padding:8px; background:var(--glass); border:1px solid rgba(255,255,255,0.06); cursor:pointer}
    .screen-title {font-size:12px;color:var(--muted); text-transform:uppercase;letter-spacing:1px}
    .small {font-size:13px;color:var(--muted)}

    /* entrance animations */
    .card, .preview {opacity:0; transform:translateY(18px) scale(.995); animation:enter .6s forwards cubic-bezier(.2,.9,.3,1)}
    .card {animation-delay:.08s} .preview{animation-delay:.18s}
    @keyframes enter { to {opacity:1; transform:none} }

    /* next-step placeholder (visible after start) */
    .next-step {
      margin-top:18px;
      padding:14px;border-radius:10px;background:linear-gradient(90deg,rgba(150,95,170,0.06),rgba(120,160,240,0.04));
      color:var(--text);
      display:flex;align-items:center;justify-content:space-between;
      gap:12px;
    }


    .start-btn {
      background: linear-gradient(135deg, #FFD26F 0%, #FFA76F 100%);
      color: #222;
      border: none;
      padding: 14px 28px;
      border-radius: 50px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s ease;
      box-shadow: 0 4px 10px rgba(255, 165, 111, 0.3);
    }

    .start-btn:hover {
      transform: translateY(-3px);
      box-shadow: 0 6px 14px rgba(255, 165, 111, 0.4);
    }

    .illustration {
      width: 350px;
      animation: float 3.5s ease-in-out infinite;
    }

    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }

    @keyframes slideUp {
      from { transform: translateY(30px); opacity: 0; }
      to { transform: translateY(0); opacity: 1; }
    }

    @keyframes float {
      0%, 100% { transform: translateY(0px); }
      50% { transform: translateY(-15px); }
    }

    @media (max-width: 900px) {
      .container {
        flex-direction: column;
        gap: 40px;
        text-align: center;
      }
      .welcome-card {
        width: 90%;
        padding: 40px 25px;
      }
      .illustration {
        width: 300px;
      }
    }

    /* Responsive */
    @media (max-width:980px) {
      .stage { grid-template-columns:1fr; }
      .preview { order: -1; height: auto; }
      .card{ margin-bottom: 16px;}
    }
    @media (max-width:480px) {
      .title{font-size:20px}
      .logo-circle{width:48px;height:48px;font-size:18px}
      .preview{height:auto;padding:14px}
    }
  </style>
</head>
<body class="<?php echo $body_class; ?>">
  <div class="wrap">
    <div class="stage" role="main" aria-labelledby="onboardTitle">
      <div class="card" aria-hidden="false">
        <button class="theme-toggle" id="themeToggle" aria-label="Toggle theme">🌓</button>

        <div class="brand" aria-hidden="true">
          <div class="logo-circle">L</div>
          <div>
            <h5>Lingoland</h5>
            <p class="small">AI-powered English learning</p>
          </div>
        </div>

        <div class="hero" role="region" aria-labelledby="onboardTitle">
          <div class="screen-title">Step 1 of 6</div>
          <h1 id="onboardTitle" class="title">Welcome to Lingoland!</h1>
          <p class="subtitle">We’ll personalize your learning path — choose pace, style and goals. This takes under a minute.</p>

          <div class="progress-top" aria-hidden="true">
            <div class="dots" id="dots">
              <div class="dot active"></div>
              <div class="dot"></div>
              <div class="dot"></div>
              <div class="dot"></div>
              <div class="dot"></div>
              <div class="dot"></div>
            </div>
            <div class="muted">Quick • Personalized • Fun</div>
          </div>

          <div style="margin-top:18px; display:flex; flex-direction:column; gap:12px;">
            <div class="muted">What we’ll do for you:</div>
            <ul style="margin:0 0 8px 18px; color:var(--muted)">
              <li>Match lessons to your level</li>
              <li>Suggest goals and schedules</li>
              <li>Pick games & quizzes you'll enjoy</li>
            </ul>

            <div class="cta-row">
              <button class="btn primary" id="startBtn" aria-label="Start personalizing">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true" style="transform:translateY(-1px)"><path d="M5 12h14M12 5l7 7-7 7" stroke="white" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Start Personalizing
              </button>
              <button class="btn ghost" id="learnMore">How it works</button>
            </div>

            <div class="small muted">By continuing you agree to our <a href="#" style="color:inherit;text-decoration:underline">Terms</a>.</div>
          </div>
        </div>

      </div>

      <aside class="preview" aria-label="Preview area">
        <div class="screen-title">Preview • Smart suggestions</div>
        <h4>Example recommendations</h4>
        <p class="small">These are the kind of courses you’ll receive after personalization.</p>

        <div style="display:flex;flex-direction:column;gap:10px;margin-top:10px">
          <div class="tile">
            <div class="thumb">A1</div>
            <div>
              <div class="meta">Everyday Conversations</div>
              <div class="sub">10 lessons • 30 min/week</div>
            </div>
          </div>

          <div class="tile">
            <div class="thumb">G</div>
            <div>
              <div class="meta">Grammar Essentials</div>
              <div class="sub">5 lessons • Focused explanations</div>
            </div>
          </div>

          <div class="tile">
            <div class="thumb">Q</div>
            <div>
              <div class="meta">Quick Games</div>
              <div class="sub">Daily practice • Gamified</div>
            </div>
          </div>
        </div>

        <div class="next-step" style="margin-top:auto">
          <div>
            <div style="font-weight:700">Ready when you are</div>
            <div class="small muted">Tap start to shape your plan</div>
          </div>
          <div>
            <button class="btn primary" id="startBtn2">Start</button>
          </div>
           
        </div>
  </div>
      </aside>
      <img src="../img/onboarding_img1.png" alt="Learning Illustration" class="illustration" />
    </div>
  </div>

  <script>
const themeToggle = document.getElementById('themeToggle');

// Save theme to DB
async function saveThemeToDatabase(themeId) {
    try {
        const formData = new FormData();
        formData.append('theme_id', themeId);
        formData.append('user_id', <?php echo $user_id; ?>);

        const response = await fetch('update_theme.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();
        console.log("Theme save result:", result);
    } catch (err) {
        console.error("Theme save error:", err);
    }
}

// Theme toggle click
themeToggle.addEventListener('click', async () => {
    const isDark = document.body.classList.toggle('dark');
    const themeId = isDark ? 2 : 1;

    // save locally
    localStorage.setItem("lingo_theme", isDark ? "dark" : "light");

    // save in DB
    await saveThemeToDatabase(themeId);
});

// Load stored theme
window.addEventListener("DOMContentLoaded", () => {
    const savedTheme = localStorage.getItem("lingo_theme");
    if (savedTheme === "dark") {
        document.body.classList.add("dark");
    }
});

// Your existing next-step animations remain untouched
const startBtn = document.getElementById('startBtn');
const startBtn2 = document.getElementById('startBtn2');
startBtn.addEventListener('click', goNext);
startBtn2.addEventListener('click', goNext);

function goNext() {
    window.location.href = "onboarding_step2.php"; 
}
</script>
