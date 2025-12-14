<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


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

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}


$courses = $conn->query("SELECT course_id FROM course");
while ($course = $courses->fetch_assoc()) {
    $course_id = $course['course_id'];


    $sql_total_lessons = "SELECT COUNT(*) AS total_lessons FROM lesson WHERE course_id = ?";
    $stmt = $conn->prepare($sql_total_lessons);
    $stmt->bind_param("i", $course_id);
    $stmt->execute();
    $total_lessons = $stmt->get_result()->fetch_assoc()['total_lessons'];
    $stmt->close();

    if ($total_lessons == 0) continue;


    $sql_completed_lessons = "
        SELECT COUNT(DISTINCT p.lesson_id) AS completed_lessons
        FROM progresses_in p
        JOIN lesson l ON p.lesson_id = l.lesson_id
        WHERE p.user_id = ? AND l.course_id = ?";
    $stmt = $conn->prepare($sql_completed_lessons);
    $stmt->bind_param("ii", $user_id, $course_id);
    $stmt->execute();
    $completed_lessons = $stmt->get_result()->fetch_assoc()['completed_lessons'];
    $stmt->close();

    $sql_total_quizzes = "
        SELECT COUNT(*) AS total_quizzes
        FROM quiz q
        JOIN lesson l ON q.lesson_id = l.lesson_id
        WHERE l.course_id = ?";
    $stmt = $conn->prepare($sql_total_quizzes);
    $stmt->bind_param("i", $course_id);
    $stmt->execute();
    $total_quizzes = $stmt->get_result()->fetch_assoc()['total_quizzes'];
    $stmt->close();

   
    $sql_attempted_quizzes = "
        SELECT COUNT(DISTINCT qa.quiz_id) AS attempted_quizzes
        FROM quiz_attempt qa
        JOIN quiz q ON qa.quiz_id = q.quiz_id
        JOIN lesson l ON q.lesson_id = l.lesson_id
        WHERE qa.user_id = ? AND l.course_id = ?";
    $stmt = $conn->prepare($sql_attempted_quizzes);
    $stmt->bind_param("ii", $user_id, $course_id);
    $stmt->execute();
    $attempted_quizzes = $stmt->get_result()->fetch_assoc()['attempted_quizzes'];
    $stmt->close();


    if ($completed_lessons == $total_lessons && $attempted_quizzes == $total_quizzes) {

        $sql_cert = "SELECT certificate_id FROM certificate WHERE course_id = ?";
        $stmt = $conn->prepare($sql_cert);
        $stmt->bind_param("i", $course_id);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($res) {
            $certificate_id = $res['certificate_id'];

       
            $check = $conn->prepare("SELECT * FROM certificate_issue WHERE certificate_id = ? AND user_id = ?");
            $check->bind_param("ii", $certificate_id, $user_id);
            $check->execute();
            $exists = $check->get_result()->num_rows > 0;
            $check->close();

            if (!$exists) {
                $insert = $conn->prepare("INSERT INTO certificate_issue (certificate_id, user_id, issue_date) VALUES (?, ?, NOW())");
                $insert->bind_param("ii", $certificate_id, $user_id);
                $insert->execute();
                $insert->close();
            }
        }
    }
}


?>
