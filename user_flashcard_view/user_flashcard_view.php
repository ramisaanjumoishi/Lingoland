
<?php

session_start();


if (!isset($_SESSION['user_id'])) {
   
    header("Location: ../login/login_process.php");
    exit();
}


$user_id = $_SESSION['user_id'];


if (isset($_POST['card_id'])) {
    $flashcard_id = $_POST['card_id'];
    log_user_activity($user_id, 'flashcard reviewed');
} else {
   
    die('Flashcard ID is missing!');
}


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


$sql = "SELECT * FROM flashcard WHERE card_id = '$flashcard_id'";
$result = mysqli_query($conn, $sql);

if (!$result) {
    die('Error in query execution: ' . mysqli_error($conn));
}

$flashcard = mysqli_fetch_assoc($result);

$check_query = "SELECT * FROM user_flashcard WHERE user_id = '$user_id' AND card_id = '$flashcard_id'";
$check_result = mysqli_query($conn, $check_query);

if (mysqli_num_rows($check_result) > 0) {
  
    $update_query = "UPDATE user_flashcard SET status = status + 1, last_reviewed = NOW() WHERE user_id = '$user_id' AND card_id = '$flashcard_id'";
    if (mysqli_query($conn, $update_query)) {
        //echo "Flashcard review count updated successfully!";
    } else {
        echo "Error: " . mysqli_error($conn);
    }
} else {
  
    $status = 1; 
    $last_reviewed = date("Y-m-d H:i:s"); 

    $insert_query = "INSERT INTO user_flashcard (user_id, card_id, status, last_reviewed) 
                     VALUES ('$user_id', '$flashcard_id', '$status', '$last_reviewed')";

    if (mysqli_query($conn, $insert_query)) {
        //echo "Flashcard saved successfully!";
    } else {
        echo "Error: " . mysqli_error($conn);
    }
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
    
    
    <link href="user_flashcard_view.css" rel="stylesheet">
  
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
                <li><a href="../user_dashboard/logout.php"><img src="../img/icon24.png" alt="Logout Icon">Logout</a></li>
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
             
            <div class="flashcard-details-section">
                <h2 class="flashcard-title"><?php echo htmlspecialchars($flashcard['meaning']); ?></h2> <!-- Meaning as Title -->
                
                <div class="flashcard-content">
                    <h5>Word Usage:</h5>
                    <p><?php echo htmlspecialchars($flashcard['word_usage']); ?></p>
                </div>

                
                <?php if ($flashcard['image_url'] != ''): ?>
                    <img src="<?php echo $flashcard['image_url']; ?>" alt="Flashcard Image" class="flashcard-image">
                <?php endif; ?>
            </div>
        </div>
    </div>

<script>
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