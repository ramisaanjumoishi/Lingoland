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
  <title>Lingoland — Step 6: Learning Time</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
  <canvas id="sparkCanvas"></canvas>
  <style>
    :root {
      --bg-1:#f5a7d3; --bg-2:#97c0fc;
      --accent-1:#6a4c93; --accent-2:#9b59b6;
      --muted:#6b6b6b; --card:#ffffff;
      --glass:rgba(255,255,255,0.45); --radius:14px;
      --shadow:0 10px 30px rgba(18,18,18,0.08);
      --text:#1f1f1f;
    }
    body.dark {
      --bg-1:#2a2540; --bg-2:#2e2b3f;
      --accent-1:#b89cd6; --accent-2:#8e6bb8;
      --muted:#cfcfe6; --card:#12121a;
      --glass:rgba(255,255,255,0.03);
      --shadow:0 8px 24px rgba(0,0,0,0.6);
      --text:#f4f4f8;
    }
    html,body {
      height:110%;margin:0;font-family:'Poppins',sans-serif;
      background:linear-gradient(135deg,var(--bg-1),var(--bg-2));
      color:var(--text);
    }
    .wrap{min-height:100%;display:flex;align-items:center;justify-content:center;padding:36px;}
    .stage{max-width:1100px;display:grid;grid-template-columns:460px 1fr;gap:28px;align-items:center;}
    .card{
      background:linear-gradient(180deg,rgba(255,255,255,0.08),rgba(255,255,255,0.02));
      border-radius:var(--radius);padding:32px;box-shadow:var(--shadow);
      backdrop-filter:blur(8px);position:relative;overflow:hidden;
      border:1px solid rgba(255,255,255,0.08);
      margin-top: -350px;
      width:500px;
      margin-left: -120px;
    }
    .brand{display:flex;gap:12px;align-items:center;margin-bottom:16px;}
    .logo-circle{width:56px;height:56px;border-radius:12px;
      background:linear-gradient(135deg,var(--accent-1),var(--accent-2));
      display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:20px;
      box-shadow:0 6px 18px rgba(0,0,0,0.18);}
    .title{font-size:26px;margin:12px 0 8px;font-weight:700;}
    .subtitle{font-size:15px;color:var(--muted);margin-bottom:18px;}
    .progress-top{display:flex;align-items:center;gap:8px;margin-bottom:16px;}
    .dots{display:flex;gap:6px;}
    .dot{width:10px;height:10px;border-radius:6px;background:rgba(255,255,255,0.12);}
    .dot.active{width:28px;background:linear-gradient(90deg,var(--accent-1),var(--accent-2));box-shadow:0 6px 14px rgba(105,64,140,0.18);}
    .btn{
      padding:12px 22px;border-radius:12px;border:none;cursor:pointer;
      font-weight:600;background:linear-gradient(90deg,var(--accent-1),var(--accent-2));
      color:white;font-size:15px;box-shadow:0 6px 18px rgba(106,76,147,0.25);
      transition:all .25s ease;
    }
    .btn:hover{transform:translateY(-3px);box-shadow:0 8px 20px rgba(106,76,147,0.4);}
    .theme-toggle{position:absolute;top:18px;right:18px;border-radius:50%;padding:8px;
      background:var(--glass);border:1px solid rgba(255,255,255,0.08);cursor:pointer;}
    .preview{
      height:470px;border-radius:var(--radius);padding:18px;
      display:flex;flex-direction:column;justify-content:space-between;
      background:linear-gradient(180deg,rgba(255,255,255,0.03),rgba(255,255,255,0.01));
      border:1px solid rgba(255,255,255,0.04);box-shadow:var(--shadow);
      margin-top: -350px;
      width: 120%;
    }
  /* Confetti sparkle animation */
    @keyframes sparkle {
      0% { opacity: 0; transform: translateY(0) scale(1);}
      50% { opacity: 1; transform: translateY(-10px) scale(1.2);}
      100% { opacity: 0; transform: translateY(-20px) scale(0.8);}
    }

    .sparkle {
      position:absolute;
      width:6px; height:6px;
      border-radius:50%;
      background:linear-gradient(45deg,#ffd26f,#ffa76f);
      animation: sparkle 1.5s infinite ease-in-out;
    }
  .card, .preview {
    position: relative;
    z-index: 2;
  }


    .tile{background:var(--card);border-radius:10px;padding:12px;margin-bottom:10px;
      box-shadow:0 6px 18px rgba(11,11,12,0.08);transition:transform .22s;}
    .tile:hover{transform:translateY(-5px);}
    .illustration{width:240px;align-self:center; float: 3.5s ease-in-out infinite;}
    @keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-15px)}}
    .opt{padding:12px 20px;border-radius:10px;border:1px solid rgba(0,0,0,0.06);
      cursor:pointer;background:var(--card);font-weight:500;transition:all .2s;}
    .opt:hover{transform:translateY(-2px);}
    .opt.selected{background:linear-gradient(90deg,var(--accent-1),var(--accent-2));color:#fff;}
    @keyframes slideUp {
  from { transform: translateY(30px); opacity: 0; }
  to { transform: translateY(0); opacity: 1; }
}
@keyframes fadeIn {
  from { opacity: 0; transform: scale(0.97); }
  to { opacity: 1; transform: scale(1); }
}
.card, .preview {
  animation: slideUp 0.7s ease-out forwards;
}
.illustration {
  animation: fadeIn 1.1s ease-out forwards, float 3.5s ease-in-out infinite;
}

    @media(max-width:980px){.stage{grid-template-columns:1fr}.preview{order:-1;height:auto}.illustration{display:none}}
  </style>
</head>
<body class="<?php echo $body_class; ?>">
  <div class="wrap">
    <div class="stage">
      <div class="card">
        <button class="theme-toggle" id="themeBtn">🌓</button>

        <div class="brand">
          <div class="logo-circle">L</div>
          <div><h5>Lingoland</h5><p class="small muted">AI-powered English learning</p></div>
        </div>

        <div class="screen-title">Step 6 of 6</div>
        <h1 class="title">⏰ How much time will you spend daily?</h1>
        <p class="subtitle">We’ll tailor your daily lessons and reminders based on your availability.</p>

        <div class="progress-top">
          <div class="dots">
            <div class="dot"></div><div class="dot"></div><div class="dot"></div>
            <div class="dot"></div><div class="dot"></div><div class="dot"></div>
            <div class="dot active"></div>
          </div>
          <div class="muted">Final step!</div>
        </div>

        <form id="timeForm" action="save_time.php" method="POST">
          <div style="display:flex;flex-wrap:wrap;gap:10px;margin:12px 0;">
            <button type="button" class="opt" data-value="10-15 min/day">☕ 10–15 min/day</button>
            <button type="button" class="opt" data-value="20-30 min/day">📘 20–30 min/day</button>
            <button type="button" class="opt" data-value="45-60 min/day">🚀 45–60 min/day</button>
            <button type="button" class="opt" data-value="1 hour+">🔥 1 hour or more</button>
          </div>
          <input type="hidden" name="daily_time" id="daily_time">

          <p class="subtitle">That’s it! You’re all set to start your journey with personalized lessons, reminders, and rewards.</p>

          <div style="margin-top:28px;text-align:center">
            <button class="btn" type="submit" id="finishBtn">Finish & Go to Dashboard →</button>
          </div>
        </form>
      </div>

      <aside class="preview">
        <div class="screen-title">Preview</div>
        <h4>Why we ask this</h4>
        <div class="tile">⏳ To pace your daily tasks efficiently</div>
        <div class="tile">🧭 Keeps lessons within your comfort zone</div>
        <div class="tile">💬 Helps set realistic learning streak goals</div>
        <img src="../img/onboarding_img6.png" alt="Study illustration" class="illustration" />
      </aside>
    </div>
  </div>

  <script>

    const finishBtn = document.getElementById('finishBtn');


    // option selection
    document.querySelectorAll('.opt').forEach(btn=>{
      btn.addEventListener('click',()=>{
        const group=btn.parentElement;
        group.querySelectorAll('.opt').forEach(b=>b.classList.remove('selected'));
        btn.classList.add('selected');
        document.getElementById('daily_time').value=btn.dataset.value;
      });
    });

    // smooth transition like step 1
    document.getElementById('timeForm').addEventListener('submit', async (e) => {
  e.preventDefault();

  const selected = document.getElementById('daily_time').value;
  if (!selected) {
    alert("Please select a daily study time first!");
    return;
  }

  // Send to PHP
  const formData = new FormData();
  formData.append('daily_time', selected);

  const card = document.querySelector('.card');
  card.style.transition = 'transform .5s ease, opacity .5s ease';
  card.style.transform = 'translateX(-40px) scale(.98)';
  card.style.opacity = '.92';

  try {
    const response = await fetch('save_time.php', {
      method: 'POST',
      body: formData
    });
    const result = await response.text();
    console.log(result);
    setTimeout(() => {
      window.location.href = '../user_dashboard/user-dashboard.php';
    }, 700);
  } catch (err) {
    console.error(err);
    alert("Something went wrong saving your time!");
  }
});


// Sparkle confetti for a celebratory touch
    function createSparkle() {
      const sparkle = document.createElement('div');
      sparkle.className = 'sparkle';
      sparkle.style.left = Math.random() * 100 + '%';
      sparkle.style.top = Math.random() * 100 + '%';
      sparkle.style.animationDuration = (1 + Math.random()*1.5) + 's';
      document.body.appendChild(sparkle);
      setTimeout(()=> sparkle.remove(), 2000);
    }

    setInterval(createSparkle, 300);

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
  </script>
</body>
</html>
