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

$sql = "SELECT profile_picture FROM user WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $_SESSION['profile_picture'] = $row['profile_picture']; 
}


$sql = "SELECT theme_id FROM sets WHERE user_id = ? ORDER BY set_on DESC LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$theme_id = 1; 
if ($row = $result->fetch_assoc()) {
    $theme_id = $row['theme_id'];
}

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

$stmt = $conn->prepare("SELECT COUNT(DISTINCT lesson_id) AS completed_lessons 
                        FROM progresses_in 
                        WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$completed_lessons = $res['completed_lessons'] ?? 0;
$stmt->close();


$stmt = $conn->prepare("SELECT COUNT(DISTINCT badge_id) AS badges 
                        FROM earned_by 
                        WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$badges_earned = $res['badges'] ?? 0;
$stmt->close();


$sql = "SELECT score, first_name, last_name FROM `user` WHERE user_id = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) { die("Prepare failed (user score): ".$conn->error); }
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$user_score = $res['score'] ?? 0;
$first_name = $res['first_name'] ?? '';
$last_name  = $res['last_name'] ?? '';
$stmt->close();



$sql = "SELECT l.course_id,
               c.title AS course_title,
               COUNT(DISTINCT l.lesson_id) AS total_lessons,
               COUNT(DISTINCT pi.lesson_id) AS completed
        FROM lesson l
        JOIN course c ON c.course_id = l.course_id
        LEFT JOIN progresses_in pi
               ON pi.lesson_id = l.lesson_id AND pi.user_id = ?
        GROUP BY l.course_id, c.title";

$stmt = $conn->prepare($sql);
if (!$stmt) { die("Prepare failed (progress per course): ".$conn->error); }
$stmt->bind_param("i", $user_id);
$stmt->execute();
$progress_res = $stmt->get_result();

$course_progress = [];
while ($row = $progress_res->fetch_assoc()) {
    $pct = ($row['total_lessons'] > 0)
         ? round(($row['completed'] / $row['total_lessons']) * 100)
         : 0;
    $course_progress[] = [
        'course_id'     => $row['course_id'],
        'course_title'  => $row['course_title'],
        'percentage'    => $pct
    ];
}
$stmt->close();


$sql = "SELECT COUNT(DISTINCT lesson_id) AS completed_lessons
        FROM progresses_in WHERE user_id = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) { die("Prepare failed (completed lessons): ".$conn->error); }
$stmt->bind_param("i", $user_id);
$stmt->execute();
$completed_lessons = ($stmt->get_result()->fetch_assoc()['completed_lessons'] ?? 0);
$stmt->close();


$sql = "SELECT COUNT(DISTINCT badge_id) AS badges
        FROM earned_by WHERE user_id = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) { die("Prepare failed (badges): ".$conn->error); }
$stmt->bind_param("i", $user_id);
$stmt->execute();
$badges_earned = ($stmt->get_result()->fetch_assoc()['badges'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("SELECT first_name FROM user WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($first_name);
$stmt->fetch();
$stmt->close();


$sql = "SELECT n.message, a.is_sent
        FROM are_sent a
        JOIN notification n ON a.notification_id = n.notification_id
        WHERE a.user_id = ?
        ORDER BY a.is_sent DESC
        LIMIT 3";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$notif_res = $stmt->get_result();
$latest_notifications = $notif_res ? $notif_res->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();



$sql = "
    SELECT 
        l.user_id,
        CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS full_name,
        l.xp, 
        l.rank,
        COALESCE(COUNT(DISTINCT e.badge_id), 0) AS badges
    FROM leaderboard l
    JOIN `user` u ON u.user_id = l.user_id
    JOIN earned_by e ON l.user_id = e.user_id
    GROUP BY l.user_id, full_name, l.xp, l.rank
    ORDER BY l.rank ASC
    LIMIT 5
";

$result = $conn->query($sql);
$leaderboard = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lingoland User Dashboard</title>
    
   
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    
    <link href="user-dashboard.css" rel="stylesheet">


    
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500&family=Poppins:wght@500&display=swap" rel="stylesheet">
</head>
<body class="<?php echo $theme_id == 2 ? 'dark' : ''; ?>">
    <div class="container-fluid">
        
        <nav id="sidebar" class="sidebar">
            <div class="sidebar-header">
                <img src="../img/logo.png" alt="Lingoland Logo" class="logo">
            </div>
            <ul class="list-unstyled">
                <li><a href="user-dashboard.php"><img src="../img/icon10.png" alt="Home Icon"> Dashboard</a></li>
                <li><a href="../courses/courses.php"><img src="../img/icon11.png" alt="Courses Icon"> Courses</a></li>
                <li><a href="../leaderboard/view_leaderboard.php"><img src="../img/icon12.png" alt="Leaderboard Icon"> Leaderboard</a></li>
                <li><a href="../user_badge/user_badge.php"><img src="../img/icon13.png" alt="Badges Icon"> Badges</a></li>
                <li><a href="../user_flashcards/user_flashcards.php"><img src="../img/icon14.png" alt="Flashcards Icon"> Flashcards</a></li>
                <li><a href="../user_dashboard_words/user_dashboard_words.php"><img src="../img/icon26.png" alt="Vocabulary Icon"> Vocabulary</a></li>
                <li><a href="../user_forum_post/user_forum_post.php"><img src="../img/icon15.png" alt="Forum Icon"> Forum</a></li>
                <li><a href="../settings/settings.php"><img src="../img/icon16.png" alt="Settings Icon"> Settings</a></li>
                <li><a href="../user_certificate/user_certificate.php"><img src="../img/icon17.png" alt="Certificate Icon"> Certificates</a></li>
                <li><a href="../notification_view/notification_view.php"><img src="../img/icon18.png" alt="Notifications Icon"> Notifications</a></li>
                <li><a href="../user_message/user_message.php"><img src="../img/icon19.png" alt="Contact Icon"> Contact Us</a></li>
                <li><a href="../user_dashboard/logout.php"><img src="../img/icon24.png" alt="Logout Icon"> Logout</a></li>
            </ul>
        </nav>

      
        <div class="main-content">
            
           <nav class="navbar navbar-expand-lg <?php echo $theme_id == 2 ? 'navbar-dark bg-dark' : 'navbar-light bg-light'; ?>">
            <button class="btn btn-outline-secondary d-lg-none me-2" id="sidebarToggle" aria-label="Toggle sidebar">☰</button>
                <a class="navbar-brand" href="user-dashboard.php">Lingoland Dashboard</a>
                <div class="profile">
                    <?php 
                    $profileImage = !empty($_SESSION['profile_picture']) ? "../settings/" . $_SESSION['profile_picture'] : '../img/icon9.png'; 
                    ?>
                    <img src="<?php echo htmlspecialchars($profileImage); ?>" alt="User" class="profile-pic">
                    <div class="dropdown">
                        <button class="btn btn-secondary dropdown-toggle" type="button" id="dropdownMenuButton" data-bs-toggle="dropdown" aria-expanded="false">
                            User
                        </button>
                        <ul class="dropdown-menu" aria-labelledby="dropdownMenuButton">
                            <li><a class="dropdown-item" href="../settings/settings.php">Settings</a></li>
                            <li><a class="dropdown-item" href="logout.php">Logout</a></li>
                        </ul>
                    </div>
                </div>
            </nav>
            <div class="dashboard-main">
         <div class="hero-section">
            <h1>Welcome Back, <?php echo htmlspecialchars($first_name); ?>!</h1>
            <p>Your personalized learning journey continues. Let's make today a great day!</p>
            <a href="../courses/courses.php" class="cta-button">Start Learning</a>
        </div>

           <div class="row stats-row">
            <div class="col-md-4 stat-box completed-courses">
                <h5>Completed Lessons</h5>
                <h4><?php echo $completed_lessons; ?></h4>
            </div>
            <div class="col-md-4 stat-box badges-earned">
                <h5>Badges Earned</h5>
                <h4><?php echo $badges_earned; ?></h4>
            </div>
            <div class="col-md-4 stat-box streak">
                <h5>Score</h5>
                <h4><?php echo $user_score; ?></h4>
            </div>
            </div>

            <div class="row">
            <div class="col-md-6 progress-section">
                <h4>Your Learning Progress</h4>
                <div class="progress-container">
               
                <?php foreach ($course_progress as $cp): ?>
                <div class="progress mb-2">
                    <div class="progress-bar" role="progressbar"
                        style="width: <?= $cp['percentage'] ?>%;"
                        aria-valuenow="<?= $cp['percentage'] ?>" aria-valuemin="0" aria-valuemax="100">
                    <?= htmlspecialchars($cp['course_title']) ?>: <?= $cp['percentage'] ?>%
                    </div>
                </div>
                <?php endforeach; ?>

                </div>
            </div>

        <div class="col-md-6 latest-notifications">
            <h4>Latest Notifications</h4>
            <ul class="list-group">
                <?php if (!empty($latest_notifications)): ?>
                    <?php foreach ($latest_notifications as $ln): ?>
                        <li class="list-group-item notification-item">
                            <?php echo htmlspecialchars(substr($ln['message'], 0, 80)); ?>
                            <?php if (strlen($ln['message']) > 80) echo "..."; ?>
                            <div class="notif-time">
                                <?php echo date("d M Y, h:i A", strtotime($ln['is_sent'])); ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                <?php else: ?>
                    <li class="list-group-item">No notifications yet.</li>
                <?php endif; ?>
            </ul>
            <a href="../notification_view/notification_view.php" class="btn btn-sm btn-primary mt-2">View More</a>
        </div>

            <div class="leaderboard">
            <h4>Top 5 Users</h4>
            <div class="table-responsive">
            <table class="table table-hover leaderboard-table">
                <thead>
                <tr>
                    <th>User</th>
                    <th>Score</th>
                    <th>Badges</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($leaderboard as $lb): ?>
                    <tr>
                    <td><?php echo htmlspecialchars($lb['full_name']); ?></td>
                    <td><?php echo $lb['xp']; ?></td>
                    <td><?php echo $lb['badges']; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <a href="../leaderboard/view_leaderboard.php" class="btn btn-sm btn-primary">View More</a>
          </div>

        </div>
    </div>
</div> 
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.2/dist/umd/popper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.min.js"></script>

<script>
  (function () {
    const btn = document.getElementById('sidebarToggle');
    if (btn) {
      btn.addEventListener('click', function () {
        document.body.classList.toggle('sidebar-open');
      });
    }
  })();
</script>

</body>
</html>
