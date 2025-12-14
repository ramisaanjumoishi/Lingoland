<?php
if (session_status() === PHP_SESSION_NONE) session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli("localhost", "root", "", "lingoland_db");
$conn->set_charset('utf8mb4');

/* 1. USERS WHO HAVE BADGES */
$res = $conn->query("
  SELECT DISTINCT u.user_id, u.score
  FROM user u
  WHERE EXISTS (SELECT 1 FROM earned_by eb WHERE eb.user_id = u.user_id)
");

/* 2. INSERT / UPDATE LEADERBOARD */
$up = $conn->prepare("
  INSERT INTO leaderboard (user_id, xp, rank, last_updated)
  VALUES (?, ?, 0, NOW())
  ON DUPLICATE KEY UPDATE xp = VALUES(xp), last_updated = NOW()
");

while ($row = $res->fetch_assoc()) {
  $uid = (int)$row['user_id'];
  $xp  = (int)$row['score'];
  $up->bind_param("ii", $uid, $xp);
  $up->execute();
}
$up->close();

/* 3. REMOVE USERS WHO HAVE NO BADGES ANYMORE */
$conn->query("
  DELETE l FROM leaderboard l
  LEFT JOIN (SELECT DISTINCT user_id FROM earned_by) x ON x.user_id = l.user_id
  WHERE x.user_id IS NULL
");

/* 4. GET LEADERBOARD ORDER */
$rows = $conn->query("
  SELECT user_id, xp 
  FROM leaderboard 
  ORDER BY xp DESC, user_id ASC
")->fetch_all(MYSQLI_ASSOC);

/* 5. DENSE RANK */
$rank = 1;
$upd = $conn->prepare("UPDATE leaderboard SET rank=? WHERE user_id=?");

foreach ($rows as $r) {
    $uid = (int)$r['user_id'];
    $upd->bind_param("ii", $rank, $uid);
    $upd->execute();
    $rank++;
}
$upd->close();

?>
