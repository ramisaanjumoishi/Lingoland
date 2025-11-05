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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quiz_id'])) {
    $quiz_id = $_POST['quiz_id'];

    log_user_activity($user_id, 'quiz started');
} else {
    die("Quiz ID is missing!");
}


$sql = "SELECT * FROM quiz WHERE quiz_id = '$quiz_id'";
$quiz_result = mysqli_query($conn, $sql);
if (mysqli_num_rows($quiz_result) > 0) {
    $quiz = mysqli_fetch_assoc($quiz_result);
    $quiz_title = $quiz['title'];
    $time_limit = $quiz['time_limit'];
} else {
    die("Quiz not found.");
}

$question_sql = "SELECT * FROM question WHERE quiz_id = '$quiz_id'";
$questions_result = mysqli_query($conn, $question_sql);

if (!$questions_result) {
    die("Error fetching questions: " . mysqli_error($conn));
}

$user_id = $_SESSION['user_id'];
$attempt_number_query = "SELECT COUNT(*) AS attempt_count FROM quiz_attempt WHERE user_id = '$user_id' AND quiz_id = '$quiz_id'";
$attempt_number_result = mysqli_query($conn, $attempt_number_query);
$attempt_number_row = mysqli_fetch_assoc($attempt_number_result);
$attempt_number = $attempt_number_row['attempt_count'] + 1; 


$start_time = date("Y-m-d H:i:s");
$insert_attempt_query = "INSERT INTO quiz_attempt (user_id, quiz_id, attempt_number, start_time) 
                         VALUES ('$user_id', '$quiz_id', '$attempt_number', '$start_time')";

if (!mysqli_query($conn, $insert_attempt_query)) {
    die('Error inserting quiz attempt: ' . mysqli_error($conn));

}

$attempt_id = mysqli_insert_id($conn);

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
    
   
    <link href="../quiz/quiz.css" rel="stylesheet">

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
                <li><a href="..user_message/user_message.php"><img src="../img/icon19.png" alt="Contact Icon"> Contact Us</a></li>
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

          
            <div class="quiz-container">
                <h2 class="quiz-title"><?php echo htmlspecialchars($quiz_title); ?></h2>
                <div class="quiz-time">
                    <p>Time Limit: <span id="timer"><?php echo $time_limit; ?>:00</span></p>
                </div>

                <form method="POST" action="submit_quiz.php">
                    <div class="questions-wrapper">
                   <?php
                       
                        $questions_query = "SELECT * FROM question WHERE quiz_id = '$quiz_id'";
                        $questions_result = mysqli_query($conn, $questions_query);

                        while ($question = mysqli_fetch_assoc($questions_result)) {
                            echo '<div class="question">';
                            echo '<label>' . $question['question_text'] . '</label>';
                            echo '<input type="text" name="answer[' . $question['question_id'] . ']" required>';
                            echo '</div>';
                        }
                        ?>
                    </div>
                   <input type="hidden" name="quiz_id" value="<?php echo $quiz_id; ?>"> 
                    <input type="hidden" name="attempt_number" value="<?php echo $attempt_number; ?>"> 
                    <button type="submit" class="btn btn-primary">Submit Answer</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        var timeLeft = <?php echo $time_limit; ?> * 60;  

        function updateTime() {
            var minutes = Math.floor(timeLeft / 60);
            var seconds = timeLeft % 60;
            if (seconds < 10) {
                seconds = "0" + seconds;
            }
            document.getElementById('timer').textContent = minutes + ":" + seconds;

            if (timeLeft <= 0) {
                alert('Time is up!');
                window.location.href = "../user_dashboard/user-dashboard.html";  
            } else {
                timeLeft--;
            }
        }

        
        setInterval(updateTime, 1000);
    </script>
<script>
(function(){
  const btn = document.getElementById('sidebarToggle');
  if (btn) btn.addEventListener('click', () => document.body.classList.toggle('sidebar-open'));
})();
</script>
   
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.2/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.min.js"></script>
</body>
</html>

<?php
mysqli_close($conn);
?>