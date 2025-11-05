<?php

session_start();


if (!isset($_SESSION['user_id'])) {
    header("Location: ../login/login_process.php");
    exit();
}


$user_id = $_SESSION['user_id'];
$quiz_id = $_POST['quiz_id']; 
$attempt_number = $_POST['attempt_number']; 
$score = 0;


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


$sql = "SELECT q.question_id, q.question_text, q.correct_answer 
        FROM question q
        WHERE q.quiz_id = '$quiz_id'";
$result = mysqli_query($conn, $sql);


if (!$result) {
    die('Error in query execution: ' . mysqli_error($conn));
}

$quiz_attempt_query = "SELECT start_time FROM quiz_attempt WHERE quiz_id = '$quiz_id' AND user_id = '$user_id' AND attempt_number = '$attempt_number'";
$result = mysqli_query($conn, $quiz_attempt_query);
$row = mysqli_fetch_assoc($result);
$start_time = $row['start_time'];


$end_time = date('Y-m-d H:i:s'); 
$duration = strtotime($end_time) - strtotime($start_time); 
$duration_in_minutes = round($duration / 60, 2);


if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    log_user_activity($user_id, 'quiz completed');
    $user_answers = $_POST['answer']; 
        $score = 0;
        foreach ($user_answers as $question_id => $user_answer) {
              
        $correct_answer_query = "SELECT correct_answer FROM question WHERE question_id = '$question_id' AND quiz_id = '$quiz_id'";

      
        $correct_answer_result = mysqli_query($conn, $correct_answer_query);

        if (!$correct_answer_result) {
            die("Error executing query: " . mysqli_error($conn)); 
        }

        
        $correct_answer_row = mysqli_fetch_assoc($correct_answer_result);

    
        if (!$correct_answer_row) {
            die("No correct answer found for question_id: $question_id and quiz_id: $quiz_id");
        }

        
        $correct_answer = $correct_answer_row['correct_answer'];


       
        $is_correct = ($user_answer == $correct_answer) ? 1 : 0;

         if ($user_answer == $correct_answer_row['correct_answer']) {
            $score += 10; 
        }

      
        $insert_answer_query = "INSERT INTO answer (question_id, user_id, quiz_id, attempt_number, is_correct) VALUES ('$question_id', '$user_id', '$quiz_id', '$attempt_number', '$is_correct')";
        mysqli_query($conn, $insert_answer_query);

       
        }
}
   
 
    
    $update_score_query = "UPDATE quiz_attempt SET score = '$score' WHERE user_id = '$user_id' AND quiz_id = '$quiz_id' AND attempt_number = '$attempt_number'";
    mysqli_query($conn, $update_score_query);

        $update_user_score_query = "UPDATE user SET score = score + ? WHERE user_id = ?";
        $stmt = $conn->prepare($update_user_score_query);
        $stmt->bind_param("ii", $score, $user_id);
        $stmt->execute();
        $stmt->close();

     $update_end_time_query = "UPDATE quiz_attempt SET end_time = '$end_time' WHERE quiz_id = '$quiz_id' AND user_id = '$user_id' AND attempt_number = '$attempt_number'";
     mysqli_query($conn, $update_end_time_query);

   

mysqli_close($conn);

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
   
    <link href="submit_quiz.css" rel="stylesheet">

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
           
        <div class="main-content">
            <div class="top-navbar">
                <h2>Quiz Completed Successfully!</h2>
                <p style="font-size: 16px;">Duration: <?php echo $duration; ?></p>
                <p style="font-size: 16px;">Attempt Number: <?php echo $attempt_number; ?></p>
                <p style="font-size: 16px;">Score: <?php echo $score; ?></p>
            </div>

           
            <div class="quiz-results">
                <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Question No</th>
                            <th>Question</th>
                            <th>Your Answer</th>
                            <th>Correct Answer</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        
                        $conn = mysqli_connect($servername, $username, $password, $dbname);
                        $sql = "SELECT q.question_id, q.question_text, q.correct_answer, a.is_correct 
                                FROM question q
                                LEFT JOIN answer a ON q.question_id = a.question_id AND a.user_id = '$user_id' AND a.quiz_id = '$quiz_id' 
                                WHERE q.quiz_id = '$quiz_id' AND a.attempt_number = '$attempt_number'";

                        $result = mysqli_query($conn, $sql);

                        
                        $question_number = 1;
                        while ($row = mysqli_fetch_assoc($result)) {
                            $is_correct = ($row['is_correct'] == 1) ? 'Correct' : 'Incorrect';
                            echo "<tr>
                                    <td>{$question_number}</td>
                                    <td>{$row['question_text']}</td>
                                    <td>{$row['is_correct']}</td>
                                    <td>{$row['correct_answer']}</td>
                                    <td><span class='text-" . ($is_correct == 'Correct' ? 'success' : 'danger') . "'>{$is_correct}</span></td>
                                  </tr>";
                            $question_number++;
                        }
                        ?>
                    </tbody>
                </table>
                </div>
            </div>

           
            <div class="retry-button">
                <a href="quiz.php?quiz_id=<?php echo $quiz_id; ?>&attempt_number=<?php echo $attempt_number + 1; ?>" class="btn btn-primary">Retry Quiz</a>
            </div>

        
            <div id="fireworks"></div>

        </div>
    </div>
  <script>
        (function(){
  const btn = document.getElementById('sidebarToggle');
  if (btn) btn.addEventListener('click', () => document.body.classList.toggle('sidebar-open'));
})();
</script>
   
    <script src="fireworks.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.2/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.min.js"></script>
  
</body>
</html>

<?php
mysqli_close($conn);
?>