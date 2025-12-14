<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

$data = json_decode(file_get_contents("php://input"), true);
if (!$data) {
    echo json_encode(["success" => false, "message" => "Invalid payload"]);
    exit;
}

$user_id        = intval($data['user_id'] ?? 0);
$quiz_id        = intval($data['quiz_id'] ?? 0);
$attempt_number = intval($data['attempt_number'] ?? 0);
$score          = intval($data['score'] ?? 0);

// FIXED: Changed $user_id to !$user_id (added !)
if (!$user_id || !$quiz_id || !$attempt_number) {
    echo json_encode([
        "success" => false, 
        "message" => "Missing parameters",
        "received" => [
            "user_id" => $user_id,
            "quiz_id" => $quiz_id,
            "attempt_number" => $attempt_number,
            "score" => $score
        ]
    ]);
    exit;
}

$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "lingoland_db";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    echo json_encode(["success" => false, "message" => "DB connection error: " . $conn->connect_error]);
    exit;
}

$success = true;
$errors = [];

/* ---------------------------------------------------------
   1. UPDATE quiz_attempt TABLE WITH FINAL SCORE
   FIXED: Removed "AND" after WHERE
--------------------------------------------------------- */
$sql1 = "UPDATE quiz_attempt 
         SET score = ?, end_time = NOW() 
         WHERE user_id = ? AND quiz_id = ? AND attempt_number = ?";
$stmt1 = $conn->prepare($sql1);
if ($stmt1) {
    $stmt1->bind_param("iiii", $score, $user_id, $quiz_id, $attempt_number);
    $ok1 = $stmt1->execute();
    if (!$ok1) {
        $errors[] = "Query 1 failed: " . $stmt1->error;
        $success = false;
    } else {
        $affected_rows = $stmt1->affected_rows;
        if ($affected_rows === 0) {
            $errors[] = "No quiz_attempt record found to update";
            $success = false;
        }
    }
    $stmt1->close();
} else {
    $errors[] = "Prepare failed for query 1: " . $conn->error;
    $success = false;
}

/* ---------------------------------------------------------
   2. UPDATE USER SCORE IN user TABLE
--------------------------------------------------------- */
$sql2 = "UPDATE user 
         SET score = COALESCE(score, 0) + ? 
         WHERE user_id = ?";
$stmt2 = $conn->prepare($sql2);
if ($stmt2) {
    $stmt2->bind_param("ii", $score, $user_id);
    $ok2 = $stmt2->execute();
    if (!$ok2) {
        $errors[] = "Query 2 failed: " . $stmt2->error;
        $success = false;
    }
    $stmt2->close();
} else {
    $errors[] = "Prepare failed for query 2: " . $conn->error;
    $success = false;
}

/* ---------------------------------------------------------
   3. INSERT ACTIVITY LOG: quiz_completed
--------------------------------------------------------- */
$sql3 = "INSERT INTO activity_log (user_id, action_type, action_time) 
         VALUES (?, 'quiz completed', NOW())";
$stmt3 = $conn->prepare($sql3);
if ($stmt3) {
    $stmt3->bind_param("i", $user_id);
    $ok3 = $stmt3->execute();
    if (!$ok3) {
        $errors[] = "Query 3 failed: " . $stmt3->error;
        $success = false;
    }
    $stmt3->close();
} else {
    $errors[] = "Prepare failed for query 3: " . $conn->error;
    $success = false;
}

if ($success) {
    echo json_encode([
        "success" => true,
        "message" => "Final score saved, user updated, and activity logged.",
        "score" => $score
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => "Error updating score or activity log.",
        "errors" => $errors
    ]);
}

$conn->close();
?>