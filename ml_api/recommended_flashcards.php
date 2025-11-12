<?php
// includes/recommend_flashcards.php
function getRecommendedFlashcards($conn, $user_id, $profile_id) {
    $recommended_ids = [];

    // 1) profile
    $profile_sql = "SELECT age_group, learning_goal, target_exam, proficiency_self, personality_type, learning_style
                    FROM user_profile WHERE profile_id=? LIMIT 1";
    $stmt = $conn->prepare($profile_sql);
    $stmt->bind_param("i", $profile_id);
    $stmt->execute();
    $profile = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // 2) interests
    $interests = [];
    $int_sql = "SELECT i.interest_name FROM user_interest ui
                JOIN interest i ON ui.interest_id = i.interest_id
                WHERE ui.profile_id = ?";
    $stmt = $conn->prepare($int_sql);
    $stmt->bind_param("i", $profile_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $interests[] = strtolower($r['interest_name']);
    $stmt->close();

    // 3) fetch from view all_flashcards
    $sql = "SELECT card_id, meaning, tags, popularity, source FROM all_flashcards";
    $res = $conn->query($sql);
    $flashcards = [];
    while ($row = $res->fetch_assoc()) {
        $flashcards[$row['card_id']] = [
            'meaning' => strtolower($row['meaning'] ?? ''),
            'tags' => strtolower($row['tags'] ?? ''),
            'popularity' => (float)($row['popularity'] ?? 0),
            'source' => $row['source'] ?? 'global'
        ];
    }
    if (empty($flashcards)) return [];

    // 4) rule-based scoring (with small boost for user-created)
    $rule_scores = [];
    foreach ($flashcards as $cid => $card) {
        $score = 0;
        $tags = $card['tags'];
        $goal = strtolower($profile['learning_goal'] ?? '');
        $exam = strtolower($profile['target_exam'] ?? '');
        $prof = strtolower($profile['proficiency_self'] ?? '');
        $personality = strtolower($profile['personality_type'] ?? '');
        $learning = strtolower($profile['learning_style'] ?? '');

        if ($goal !== '' && str_contains($tags, $goal)) $score += 0.25;
        if ($exam !== '' && str_contains($tags, $exam)) $score += 0.25;
        if ($prof !== '' && str_contains($tags, $prof)) $score += 0.20;
        if ($personality !== '' && str_contains($tags, $personality)) $score += 0.15;
        if ($learning !== '' && str_contains($tags, $learning)) $score += 0.10;

        foreach ($interests as $int) {
            if ($int !== '' && str_contains($tags, $int)) $score += 0.10;
        }

        // popularity factor (normalized)
        $pop = $card['popularity'] / 100.0;
        $score += 0.30 * $pop;

        // **boost user-created slightly** (small additive bias)
        if (($card['source'] ?? '') === 'user') {
            $score += 0.05; // tweakable: 0.05 = small boost
        }

        $rule_scores[$cid] = min(1.0, $score);
    }

    // 5) check if user has history in user_flashcard
    $check = $conn->prepare("SELECT COUNT(*) AS c FROM user_flashcard WHERE user_id=? AND profile_id=?");
    $check->bind_param("ii", $user_id, $profile_id);
    $check->execute();
    $count = (int)$check->get_result()->fetch_assoc()['c'];
    $has_history = ($count > 0);
    $check->close();

    // 6) call ML API only if user has history
    $ml_scores = [];
    if ($has_history) {
        $api_url = "http://127.0.0.1:5001/recommend_flashcards";
        $payload = json_encode(['user_id' => $user_id, 'profile_id' => $profile_id]);
        $opts = ['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json",
            'content' => $payload,
            'timeout' => 3
        ]];
        $context = stream_context_create($opts);
        $resp = @file_get_contents($api_url, false, $context);
        if ($resp) {
            $decoded = json_decode($resp, true);
            if (is_array($decoded)) {
                foreach ($decoded as $cid => $v) $ml_scores[(int)$cid] = (float)$v;
            }
        }
    }

    // 7) combine final scores
    $final_scores = [];
    foreach ($flashcards as $cid => $card) {
        $r = $rule_scores[$cid] ?? 0.0;
        $m = $ml_scores[$cid] ?? 0.0;
        $final = $has_history ? (0.4 * $r + 0.6 * $m) : $r;
        $final_scores[$cid] = $final;
    }

    arsort($final_scores);
    return array_keys($final_scores);
}
?>
