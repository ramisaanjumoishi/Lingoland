from flask import Flask, request, jsonify
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.metrics.pairwise import cosine_similarity

app = Flask(__name__)

@app.route("/recommend", methods=["POST"])
def recommend():
    data = request.get_json()
    user_vec = data["user_vector"]
    courses = data["courses"]

    docs = [user_vec] + [c["features"] for c in courses]
    vectorizer = TfidfVectorizer()
    tfidf = vectorizer.fit_transform(docs)

    # user index = 0
    user_embedding = tfidf[0:1]
    course_embeddings = tfidf[1:]

    scores = cosine_similarity(user_embedding, course_embeddings)[0]

    ranked = []
    for i, c in enumerate(courses):
        ranked.append({
            "course_id": c["course_id"],
            "score": float(scores[i])
        })

    ranked_sorted = sorted(ranked, key=lambda x: x["score"], reverse=True)

    return jsonify({"ranked_courses": ranked_sorted})
    
app.run(port=5000)
