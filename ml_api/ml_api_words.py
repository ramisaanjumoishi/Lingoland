from flask import Flask, request, jsonify
import pymysql
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.metrics.pairwise import cosine_similarity

app = Flask(__name__)

def get_db():
    return pymysql.connect(
        host="localhost",
        user="root",
        password="",
        database="lingoland_db",
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor
    )

@app.route('/recommend_words', methods=['POST'])
def recommend_words():
    data = request.get_json()
    user_id = data.get("user_id")
    profile_id = data.get("profile_id")

    conn = get_db()
    cur = conn.cursor()

    # === 1️⃣ Get all words with user activity ===
    # CORRECTED: Now we have 5 placeholders for 5 parameters
    cur.execute("""
        SELECT w.word_id, w.word_text, w.meaning, w.tags, w.popularity,
               IFNULL(s.word_id, NULL) AS saved,
               IFNULL(r.reaction, 0) AS reaction,
               IFNULL(v.strength_score, 0) AS strength_score,
               IFNULL(v.ease_factor, 0) AS ease_factor
        FROM word w
        LEFT JOIN saves s ON s.word_id = w.word_id AND s.user_id = %s AND s.profile_id = %s
        LEFT JOIN word_reaction r ON r.word_id = w.word_id AND r.user_id = %s AND r.profile_id = %s
        LEFT JOIN vocabulary_progress v ON v.word_id = w.word_id AND v.profile_id = %s  -- Only profile_id, no user_id
    """, (user_id, profile_id, user_id, profile_id, profile_id))  # 5 parameters total
    # Parameter order: 
    # 1. user_id for saves
    # 2. profile_id for saves  
    # 3. user_id for word_reaction
    # 4. profile_id for word_reaction
    # 5. profile_id for vocabulary_progress
    
    words = cur.fetchall()

    if not words:
        cur.close()
        conn.close()
        return jsonify({})

    # Debug: Print first few words to check structure
    print(f"Found {len(words)} words")
    if words:
        print("First word columns:", words[0].keys())
        print("First word sample:", {k: v for k, v in words[0].items() if k != 'meaning'})

    all_docs, all_ids, liked_docs, liked_ids = [], [], [], []

    for w in words:
        text = f"{w['word_text']} {w['meaning']} {w['tags'] or ''}"
        all_docs.append(text)
        all_ids.append(w['word_id'])

        # === 2️⃣ Compute user preference weight ===
        score = 0
        if w['saved']: 
            score += 20
            print(f"Word {w['word_text']}: +20 for saved")
        if w['reaction'] == 1: 
            score += 10
            print(f"Word {w['word_text']}: +10 for reaction")
        if w['strength_score']: 
            score += w['strength_score']
            print(f"Word {w['word_text']}: +{w['strength_score']} for strength_score")
        if w['ease_factor']: 
            score += w['ease_factor'] * 2
            print(f"Word {w['word_text']}: +{w['ease_factor']*2} for ease_factor")
            
        if score > 0:
            liked_docs.append(text)
            liked_ids.append(w['word_id'])
            print(f"Word {w['word_text']} added to liked with score {score}")

    print(f"Total liked words: {len(liked_docs)}")
    
    if not liked_docs:
        cur.close()
        conn.close()
        return jsonify({})

    # === 3️⃣ TF-IDF similarity ===
    tfidf = TfidfVectorizer(stop_words='english')
    matrix = tfidf.fit_transform(all_docs)
    liked_matrix = tfidf.transform(liked_docs)
    sims = cosine_similarity(liked_matrix, matrix)
    avg_sim = sims.mean(axis=0)

    scores = {all_ids[i]: float(avg_sim[i]) for i in range(len(all_ids))}
    
    # Debug: Print top 5 recommendations
    sorted_scores = sorted(scores.items(), key=lambda x: x[1], reverse=True)[:5]
    print("Top 5 recommended word IDs and scores:", sorted_scores)
    
    cur.close()
    conn.close()
    return jsonify(scores)

if __name__ == '__main__':
    app.run(port=5002, debug=True)