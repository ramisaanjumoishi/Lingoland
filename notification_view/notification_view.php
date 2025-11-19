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


$search = "";
if (isset($_GET['search'])) {
    $search = trim($_GET['search']);
}


$sql = "SELECT a.notification_id, a.is_sent, a.is_read, n.title, n.message
        FROM are_sent a
        JOIN `notification` n ON a.notification_id = n.notification_id
        WHERE a.user_id = ?
        AND n.message LIKE ?
        ORDER BY a.is_sent DESC";


$stmt = $conn->prepare($sql);
$search_param = "%$search%";
$stmt->bind_param("is", $user_id, $search_param);
$stmt->execute();
$result = $stmt->get_result();
$notifications = $result->fetch_all(MYSQLI_ASSOC);


if (isset($_POST['mark_read'])) {
    $nid = intval($_POST['mark_read']);
    $sql_update = "UPDATE are_sent SET is_read = 1 WHERE user_id = ? AND notification_id = ?";
    $stmt_up = $conn->prepare($sql_update);
    $stmt_up->bind_param("ii", $user_id, $nid);
    $stmt_up->execute();
    exit("updated");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lingoland User Dashboard</title>
    
   
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <link href="notification_view.css" rel="stylesheet">

    
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
                <li><a href="notification_view.php"><img src="../img/icon18.png" alt="Notifications Icon"> Notifications</a></li>
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
            <!-- Main Content -->
    <div class="main">
        <div class="navbar">
            <form class="search-bar" method="get" action="">
                <input type="text" name="search" placeholder="Search notifications..." value="<?php echo htmlspecialchars($search); ?>">
                 <button type="submit" class="btn search-btn">Search</button>
            </form>
        </div>

        <h2>Notifications</h2>

        <?php foreach ($notifications as $note): ?>
        <div class="notification-card" data-id="<?php echo $note['notification_id']; ?>">
            <div class="notification-title title-<?php echo htmlspecialchars($note['title']); ?>">
                <?php echo htmlspecialchars($note['title']); ?>
            </div>
            <div class="notification-time"><?php echo date("d M Y, h:i A", strtotime($note['is_sent'])); ?></div>
            
            <div class="notification-message">
                <span class="short-text"><?php echo substr($note['message'], 0, 60) . (strlen($note['message']) > 60 ? "..." : ""); ?></span>
                <span class="full-text" style="display:none;"><?php echo htmlspecialchars($note['message']); ?></span>
            </div>
            <div class="see-more">See More</div>
        </div>
        <?php endforeach; ?>
    </div>

<script>


$(document).on("click", ".see-more", function(){
    var card = $(this).closest(".notification-card");
    var shortText = card.find(".short-text");
    var fullText = card.find(".full-text");

    if(fullText.is(":visible")){
        fullText.hide();
        shortText.show();
        $(this).text("See More");
    } else {
        fullText.show();
        shortText.hide();
        $(this).text("See Less");

        var nid = card.data("id");
        $.post("notification_view.php", { mark_read: nid }, function(res){
            console.log("Marked read: " + res);
        });
    }
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