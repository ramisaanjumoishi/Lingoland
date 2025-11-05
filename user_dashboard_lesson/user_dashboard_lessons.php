<?php

session_start();

if (!isset($_SESSION['user_id'])) {
   
    header("Location: ../login/login_proccess.php");
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

 

// Before: if (isset($_GET['course_id'])) { $course_id = $_GET['course_id']; ... }
// Change to:
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['course_id'])) {
    $course_id = $_POST['course_id'];
    log_user_activity($user_id, 'course enroll');
} else {
    // fallback if not posted
    $course_id = '';
}

$search_query = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search'])) {
    $search_query = trim($_POST['search']);
}


$sql = "SELECT lesson.lesson_id, lesson.title, lesson.content, lesson.difficulty, course.title AS course_title
        FROM lesson
        JOIN course ON lesson.course_id = course.course_id
        WHERE lesson.course_id='".mysqli_real_escape_string($conn, $course_id)."'";

if ($search_query != '') {
    $sq = mysqli_real_escape_string($conn, $search_query);
    $sql .= " AND (lesson.title LIKE '%$sq%' OR lesson.lesson_id LIKE '%$sq%')";
}

$result = mysqli_query($conn, $sql);

$sort_by = $_POST['sort_by'] ?? '';
$filter_level = $_POST['filter_level'] ?? 'all';


if (isset($_POST['reset_filters'])) {
    $sort_by = '';
    $filter_level = 'all';
}


if ($filter_level !== 'all') {
    $sql .= " AND lesson.difficulty = '" . mysqli_real_escape_string($conn, $filter_level) . "'";
}

switch ($sort_by) {
    case 'level_asc':
        $sql .= " ORDER BY FIELD(lesson.difficulty, 'easy','medium','hard') ASC";
        break;
    case 'level_desc':
        $sql .= " ORDER BY FIELD(lesson.difficulty, 'hard','medium','easy') ASC";
        break;
    case 'az':
        $sql .= " ORDER BY lesson.title ASC";
        break;
    case 'za':
        $sql .= " ORDER BY lesson.title DESC";
        break;
    default:
        $sql .= " ORDER BY lesson.lesson_id ASC";
        break;
}

$result = mysqli_query($conn, $sql);
if (!$result) {
    die('Error in query execution: ' . mysqli_error($conn));
}


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


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lingoland User Dashboard</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <link href="user_dashboard_lessons.css" rel="stylesheet">

    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500&family=Poppins:wght@500&display=swap" rel="stylesheet">
</head>
<body class="<?php echo $theme_id == 2 ? 'dark' : ''; ?>">
    <div class="container-fluid">
        
        <nav id="sidebar" class="sidebar">
            <div class="sidebar-header">
                <img src="../img/logo.png" alt="Lingoland Logo" class="logo">
            </div>
            <ul class="list-unstyled">
                <li><a href="../user_dashboard/user-dashboard.php"><img src="../img/icon10.png" alt="Home Icon"> Dashboard</a></li>
                <li><a href="../courses/courses.php"><img src="../img/icon11.png" alt="Courses Icon"> Courses</a></li>
                <li><a href="aderboard/view_leaderboard.php"><img src="../img/icon12.png" alt="Leaderboard Icon"> Leaderboard</a></li>
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
                <a class="navbar-brand" href="../user_dashboard/user-dashboard.php">Lingoland Dashboard</a>
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
                            <li><a class="dropdown-item" href="../user_dashboard/logout.php">Logout</a></li>
                        </ul>
                    </div>
                </div>
            </nav>
            
             <div class="courses-section">
                <div class="search-bar">
                    <form method="POST" action="">
                    <input type="hidden" name="course_id" value="<?php echo htmlspecialchars($course_id); ?>">
                    <input type="text" class="form-control search-input" placeholder="Search lessons..." name="search" value="<?php echo htmlspecialchars($search_query); ?>">
                    <button type="submit" class="btn search-btn">Search</button>
                </form>

                </div>
               
            <div class="filter-wrapper">
                <div class="dropdown">
                    <button class="btn btn-filter dropdown-toggle" type="button" id="sfMenu" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                        Sort & Filter
                    </button>
                    <div class="dropdown-menu dropdown-menu-end p-3 filter-menu" aria-labelledby="sfMenu">
                        <form method="POST" action="" class="filter-form">
                           
                            <div class="mb-2 small text-muted">Sort by</div>
                            <div class="btn-group-vertical w-100 mb-3" role="group">
                                <input type="radio" class="btn-check" name="sort_by" id="sort_level_asc" value="level_asc" <?php echo ($sort_by==='level_asc')?'checked':''; ?>>
                                <label class="btn btn-outline-option" for="sort_level_asc">Difficulty: Easy → Hard</label>

                                <input type="radio" class="btn-check" name="sort_by" id="sort_level_desc" value="level_desc" <?php echo ($sort_by==='level_desc')?'checked':''; ?>>
                                <label class="btn btn-outline-option" for="sort_level_desc">Difficulty: Hard → Easy</label>

                                <input type="radio" class="btn-check" name="sort_by" id="sort_az" value="az" <?php echo ($sort_by==='az')?'checked':''; ?>>
                                <label class="btn btn-outline-option" for="sort_az">A–Z (Title)</label>

                                <input type="radio" class="btn-check" name="sort_by" id="sort_za" value="za" <?php echo ($sort_by==='za')?'checked':''; ?>>
                                <label class="btn btn-outline-option" for="sort_za">Z–A (Title)</label>

                                <input type="radio" class="btn-check" name="sort_by" id="sort_default" value="" <?php echo ($sort_by==='')?'checked':''; ?>>
                                <label class="btn btn-outline-option" for="sort_default">Default</label>
                            </div>

                          
                            <div class="mb-2 small text-muted">Filter by level</div>
                            <div class="btn-group-vertical w-100 mb-3" role="group">
                                <input type="radio" class="btn-check" name="filter_level" id="lvl_all" value="all" <?php echo ($filter_level==='all')?'checked':''; ?>>
                                <label class="btn btn-outline-option" for="lvl_all">All Levels</label>

                                <input type="radio" class="btn-check" name="filter_level" id="lvl_easy" value="easy" <?php echo ($filter_level==='easy')?'checked':''; ?>>
                                <label class="btn btn-outline-option" for="lvl_easy">Easy</label>

                                <input type="radio" class="btn-check" name="filter_level" id="lvl_medium" value="medium" <?php echo ($filter_level==='medium')?'checked':''; ?>>
                                <label class="btn btn-outline-option" for="lvl_medium">Medium</label>

                                <input type="radio" class="btn-check" name="filter_level" id="lvl_hard" value="hard" <?php echo ($filter_level==='hard')?'checked':''; ?>>
                                <label class="btn btn-outline-option" for="lvl_hard">Hard</label>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-apply w-100">Apply</button>
                                <button type="submit" name="reset_filters" value="1" class="btn btn-reset w-100">Reset</button>
                            </div>
                        </form>
                    </div>
                </div>

                
                <div class="active-pills mt-2 text-end">
                    <?php if ($sort_by): ?>
                        <span class="pill"><?php echo htmlspecialchars(str_replace('_',' ', strtoupper($sort_by))); ?></span>
                    <?php endif; ?>
                    <?php if ($filter_level !== 'all'): ?>
                        <span class="pill"><?php echo ucfirst(htmlspecialchars($filter_level)); ?></span>
                    <?php endif; ?>
                </div>
            </div>

                <div class="row">
                    <?php
                    if (mysqli_num_rows($result) > 0) {
                        
                        while ($lesson = mysqli_fetch_assoc($result)) {
                            ?>
                            <div class="col-12 col-sm-6 col-lg-4 course-card">
                                <div class="card course-card-style">
                                    <div class="card-body">
                                        <h5 class="card-title"><?php echo htmlspecialchars($lesson['title']); ?></h5>
                                        <p class="card-level">Level: <?php echo htmlspecialchars($lesson['difficulty']); ?></p>
                                       <div class="btn-container">
                                        <div class="enroll-btn-container">
                                          <form method="POST" action="../lessons/lessons.php">
                                            <input type="hidden" name="lesson_id" value="<?php echo $lesson['lesson_id']; ?>"> 
                                            <button type="submit" class="enroll-btn">Start Now</button>
                                        </form>

                                        </div>
                                    </div>
                                    </div>
                                </div>
                            </div>
                            <?php
                        }
                    } else {
                        echo "<p>No lessons found!</p>";
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>
<script>
document.addEventListener("DOMContentLoaded", function () {
    const filterForm = document.querySelector(".filter-form");
    const dropdownEl = document.getElementById("sfMenu");


    filterForm.querySelectorAll(".btn-apply, .btn-reset").forEach(btn => {
        btn.addEventListener("click", function () {
            const dropdown = bootstrap.Dropdown.getInstance(dropdownEl);
            if (dropdown) dropdown.hide();
        });
    });
});


(function(){
  const btn = document.getElementById('sidebarToggle');
  if (btn) btn.addEventListener('click', () => document.body.classList.toggle('sidebar-open'));
})();


</script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.2/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.min.js"></script>
</body>
</html>

<?php

mysqli_close($conn);
?>