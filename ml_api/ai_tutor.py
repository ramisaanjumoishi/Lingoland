from flask import Flask, request, jsonify
from flask_cors import CORS  # Add this import
import pymysql
import time
import requests
import traceback

app = Flask(__name__)
CORS(app)  # Add CORS support

def get_db():
    return pymysql.connect(
        host="localhost",
        user="root",
        password="",
        database="lingoland_db",
        cursorclass=pymysql.cursors.DictCursor
    )

def ask_ollama(prompt):
    try:
        print(f"📨 Sending to Ollama: {prompt[:200]}...")
        
        r = requests.post(
            "http://localhost:11434/api/generate",
            json={
                "model": "phi3:mini",
                "prompt": f"You are an English learning tutor. Help the user with their query: {prompt}",
                "stream": False
            },
            timeout=240  # Increased timeout
        )
        r.raise_for_status()
        data = r.json()
        
        print(f"✅ Ollama response: {data.get('response', '')[:100]}...")
        return data.get("response", "Sorry, I couldn't generate a response.")

    except requests.exceptions.ConnectionError:
        print("❌ Cannot connect to Ollama - is it running on port 11434?")
        return "Ollama service is not available. Please make sure Ollama is running."
    except Exception as e:
        print(f"❌ Ollama error: {e}")
        return f"AI service error: {str(e)}"

@app.route("/ai_tutor", methods=["POST", "GET"])  # Allow both methods for testing
def ai_tutor():
    try:
        print("🎯 Received AI tutor request")
        
        if request.method == "GET":
            return jsonify({"status": "AI Tutor is running!", "port": 5001})
            
        data = request.get_json()
        print(f"📦 Request data: {data}")

        if not data:
            return jsonify({"reply": "No JSON data received"}), 400

        profile_id = data.get("profile_id")
        user_message = data.get("message")
        lesson_id = data.get("lesson_id")

        if not user_message:
            return jsonify({"reply": "No message provided"}), 400

        # If no profile_id, still respond but without personalization
        if not profile_id:
            ai_reply = ask_ollama(user_message)
            return jsonify({"reply": ai_reply})

        # Original logic with profile data
        db = get_db()
        cur = db.cursor()

        # Fetch profile
        cur.execute("SELECT * FROM user_profile WHERE profile_id=%s", (profile_id,))
        profile = cur.fetchone() or {}

        # Fetch interests
        cur.execute("""
            SELECT i.interest_name
            FROM user_interest ui
            JOIN interest i ON ui.interest_id = i.interest_id
            WHERE ui.profile_id=%s
        """, (profile_id,))
        interests = [row["interest_name"] for row in cur.fetchall()]

        # Build personalized prompt
        prompt = f"""
Student Profile:
- Learning Goal: {profile.get('learning_goal', 'Not specified')}
- Proficiency: {profile.get('proficiency_self', 'Not specified')}
- Interests: {', '.join(interests) if interests else 'Not specified'}

Student Question: {user_message}

Please provide a helpful, educational response as an English tutor:
"""
        print(f"🧠 Sending prompt to AI: {prompt}")
        ai_reply = ask_ollama(prompt)

        # Save to database if profile exists
        try:
            cur.execute("""
                INSERT INTO ai_tutor_message (profile_id, direction, content, related_lesson_id)
                VALUES (%s, 'user', %s, %s)
            """, (profile_id, user_message, lesson_id))
            
            cur.execute("""
                INSERT INTO ai_tutor_message (profile_id, direction, content, related_lesson_id)
                VALUES (%s, 'ai', %s, %s)
            """, (profile_id, ai_reply, lesson_id))
            
            db.commit()
        except Exception as db_error:
            print(f"⚠️ Database save failed: {db_error}")
            # Continue even if DB save fails

        db.close()
        
        print(f"✅ Successfully replied: {ai_reply[:100]}...")
        return jsonify({"reply": ai_reply})

    except Exception as e:
        print(f"❌ Server error: {e}")
        print(traceback.format_exc())
        return jsonify({"reply": "Server error occurred. Please try again."}), 500

if __name__ == "__main__":
    print("🔥 AI Tutor starting on http://127.0.0.1:5001")
    print("🔍 Testing Ollama connection...")
    
    # Test Ollama connection
    try:
        test_response = requests.get("http://localhost:11434/api/tags", timeout=5)
        if test_response.status_code == 200:
            print("✅ Ollama is running and accessible")
        else:
            print("❌ Ollama responded with error")
    except:
        print("❌ Cannot connect to Ollama on port 11434")
    
    app.run(port=5001, debug=True)