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

@app.route('/recommend_flashcards', methods=['POST'])
def recommend_flashcards():
    data = request.get_json()
    user_id = data.get("user_id")
    profile_id = data.get("profile_id")

    conn = get_db()
    cur = conn.cursor()

    # fetch from BOTH global + user_created flashcards
    cur.execute("""
        SELECT af.card_id, af.meaning, af.tags, af.source,
               uf.status, uf.reaction,
               IFNULL(bf.card_id, NULL) AS bookmarked
        FROM all_flashcards af
        LEFT JOIN user_flashcard uf
          ON uf.card_id = af.card_id AND uf.user_id=%s AND uf.profile_id=%s
        LEFT JOIN bookmark_flashcard bf
          ON bf.card_id = af.card_id AND bf.user_id=%s AND bf.profile_id=%s
    """, (user_id, profile_id, user_id, profile_id))

    data = cur.fetchall()
    if not data:
        return jsonify({})

    liked_docs, liked_ids, all_docs, all_ids = [], [], [], []

    for d in data:
        content = (d['meaning'] or '') + ' ' + (d['tags'] or '')
        all_docs.append(content)
        all_ids.append(d['card_id'])

        strength = 0
        if d['bookmarked']: strength += 20
        if d['reaction'] == 1: strength += 10
        if d['status']: strength += 5 * int(d['status'])
        if strength > 0:
            liked_docs.append(content)
            liked_ids.append(d['card_id'])

    if not liked_docs:
        cur.close()
        conn.close()
        return jsonify({})

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
    app.run(port=5001, debug=False)
