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
    cur.execute("""
        SELECT w.word_id, w.word_text, w.meaning, w.tags, w.popularity,
               IFNULL(s.word_id, NULL) AS saved,
               IFNULL(r.reaction, 0) AS reaction,
               IFNULL(v.strength, 0) AS strength,
               IFNULL(v.ease_factor, 0) AS ease_factor
        FROM word w
        LEFT JOIN saves s ON s.word_id = w.word_id AND s.user_id = %s AND s.profile_id = %s
        LEFT JOIN word_reaction r ON r.word_id = w.word_id AND r.user_id = %s AND r.profile_id = %s
        LEFT JOIN vocabulary_progress v ON v.word_id = w.word_id AND v.user_id = %s AND v.profile_id = %s
    """, (user_id, profile_id, user_id, profile_id, user_id, profile_id))
    words = cur.fetchall()

    if not words:
        cur.close()
        conn.close()
        return jsonify({})

    all_docs, all_ids, liked_docs, liked_ids = [], [], [], []

    for w in words:
        text = f"{w['word_text']} {w['meaning']} {w['tags'] or ''}"
        all_docs.append(text)
        all_ids.append(w['word_id'])

        # === 2️⃣ Compute user preference weight ===
        score = 0
        if w['saved']: score += 20
        if w['reaction'] == 1: score += 10
        if w['strength']: score += w['strength']
        if w['ease_factor']: score += w['ease_factor'] * 2
        if score > 0:
            liked_docs.append(text)
            liked_ids.append(w['word_id'])

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
    cur.close()
    conn.close()
    return jsonify(scores)

if __name__ == '__main__':
    app.run(port=5002, debug=False)
