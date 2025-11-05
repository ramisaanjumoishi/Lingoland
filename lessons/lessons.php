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


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lesson_id'])) {
    $lesson_id = $_POST['lesson_id'];
    log_user_activity($user_id, 'lesson completed');


    $check_query = "SELECT * FROM progresses_in WHERE user_id = '$user_id' AND lesson_id = '$lesson_id'";
    $check_result = mysqli_query($conn, $check_query);

    if (mysqli_num_rows($check_result) > 0) {
        
    } else {
        
        $status = "Completed";
        $completed_on = date("Y-m-d H:i:s"); 

        $insert_query = "INSERT INTO progresses_in (user_id, lesson_id, status, completed_on) 
                        VALUES ('$user_id', '$lesson_id', '$status', '$completed_on')";

        if (mysqli_query($conn, $insert_query)) {
            echo "Progress saved successfully!";
        } else {
            echo "Error: " . mysqli_error($conn);
        }
     }

    
    $sql = "SELECT lesson.title, lesson.content, lesson.difficulty, course.title AS course_title
            FROM lesson
            JOIN course ON lesson.course_id = course.course_id
            WHERE lesson.lesson_id = '$lesson_id'";

    $result = mysqli_query($conn, $sql);

    if ($result && mysqli_num_rows($result) > 0) {
        $lesson = mysqli_fetch_assoc($result);
    } else {
        echo "Lesson not found!";
        exit();
    }

} else {
    echo "Lesson ID is missing.";
    exit();
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
    $stmt->bind_param("iss", $user_id, $action_type, $action_time); // Using "iss" to bind user_id as integer and action_type, action_time as strings
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
    
    
    <link href="lessons.css" rel="stylesheet">
    
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
                <li><a href="../leaderboard/view_leaderboard.php"><img src="../img/icon12.png" alt="Leaderboard Icon"> Leaderboard</a></li>
                <li><a href="../user_badge/user_badge.php"><img src="../img/icon13.png" alt="Badges Icon"> Badges</a></li>
                <li><a href="../user_flashcards/user_flashcards.php"><img src="../img/icon14.png" alt="Flashcards Icon"> Flashcards</a></li>
                <li><a href="../user_dashboard_words/user_dashboard_words.php"><img src="../img/icon26.png" alt="Vocabulary Icon"> Vocabulary</a></li>
                <li><a href="../user_forum_post/user_forum_post.php"><img src="../img/icon15.png" alt="Forum Icon"> Forum</a></li>
                <li><a href="../settings/settings.php"><img src="../img/icon16.png" alt="Settings Icon"> Settings</a></li>
                <li><a href="../user_certificate/user_certificate.php"><img src="../img/icon17.png" alt="Certificate Icon"> Certificates</a></li>
                <li><a href="../notification_view/notification_view.php"><img src="../img/icon18.png" alt="Notifications Icon"> Notifications</a></li>
                <li><a href="../user_message/user_message.php"><img src="../img/icon19.png" alt="Contact Icon"> Contact Us</a></li>
                <li><a href="../user_dashboard/logout.php" ><img src="../img/icon24.png" alt="Logout Icon"> Logout</a></li>
            </ul>
        </nav>

       
        <div class="main-content">
            
             <nav class="navbar navbar-expand-lg <?php echo $theme_id == 2 ? 'navbar-dark bg-dark' : 'navbar-light bg-light'; ?>">
                <button class="btn btn-outline-secondary d-lg-none me-2" id="sidebarToggle" aria-label="Toggle sidebar">☰</button>
                <a class="navbar-brand" href="../user_dashboard/user-dashboard.php">Lingoland Dashboard</a>
                <div class="profile">
                    <img src="../img/icon9.png" alt="User" class="profile-pic">
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
           
            <div class="lesson-section">
                <h1 class="lesson-title"><?php echo htmlspecialchars($lesson['title']); ?></h1>
                <h5 class="lesson-course">Course: <?php echo htmlspecialchars($lesson['course_title']); ?></h5>
                <p class="lesson-difficulty">Difficulty: <?php echo htmlspecialchars($lesson['difficulty']); ?></p>

                <div class="lesson-content">
                    <p><?php echo nl2br(htmlspecialchars($lesson['content'])); ?></p>
                </div>

               
                <h3>Quizzes</h3>
                <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Quiz Title</th>
                            <th>Time Limit (minutes)</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                            
                            $quiz_sql = "SELECT quiz.title, quiz.time_limit, quiz.quiz_id 
                                         FROM quiz
                                         WHERE quiz.lesson_id = '$lesson_id'";

                            $quiz_result = mysqli_query($conn, $quiz_sql);

                            if (mysqli_num_rows($quiz_result) > 0) {
                                while ($quiz = mysqli_fetch_assoc($quiz_result)) {
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($quiz['title']); ?></td>
                                        <td><?php echo htmlspecialchars($quiz['time_limit']); ?> mins</td>
                                        <td>
                                            <form method="POST" action="../quiz/quiz.php">
                                                <input type="hidden" name="quiz_id" value="<?php echo $quiz['quiz_id']; ?>">
                                                <button type="submit" class="btn btn-primary">Start Now</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php
                                }
                            } else {
                                echo "<tr><td colspan='3'>No quizzes available for this lesson.</td></tr>";
                            }
                        ?>
                </table>
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