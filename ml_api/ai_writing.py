from flask import Flask, request, jsonify
from flask_cors import CORS
import requests
import traceback
import time
import json
import re

app = Flask(__name__)
CORS(app)  # Allow frontend PHP to access this API

def ask_ollama(text):
    """
    Sends the writing text to phi3:mini and requests structured evaluation.
    """
    try:
        prompt = f"""
You are an English writing evaluation model.
Analyze the following text and return a STRICT JSON in this EXACT format:

{{
  "grammar_score": <0-100>,
  "coherence_score": <0-100>,
  "vocabulary_score": <0-100>,
  "overall_score": <0-100>,
  "summary": "Brief summary of the writing quality",
  "suggestions": [
    "Specific suggestion 1",
    "Specific suggestion 2", 
    "Specific suggestion 3"
  ]
}}

Text to evaluate:
{text}

Remember: ONLY return JSON. No extra text.
"""

        print(f"📤 Sending writing to Ollama...")
        print(f"Text length: {len(text)} characters")
        
        r = requests.post(
            "http://localhost:11434/api/generate",
            json={
                "model": "phi3:mini",
                "prompt": prompt,
                "stream": False
            },
            timeout=600
        )
        r.raise_for_status()
        raw_response = r.json().get("response", "").strip()

        print("📥 Raw Ollama output:", raw_response[:500], "...\n")

        # Clean the response to extract JSON
        cleaned = extract_json_from_text(raw_response)
        
        if not cleaned:
            print("❌ Could not extract JSON from response")
            return get_fallback_response()

        data = json.loads(cleaned)
        print("✅ Parsed JSON successfully:", data)
        return data

    except Exception as e:
        print("❌ Ollama Error:", e)
        print(traceback.format_exc())
        return get_fallback_response()

def extract_json_from_text(text):
    """Extract JSON from text that might contain extra content."""
    try:
        # Try to find JSON pattern
        json_match = re.search(r'\{.*\}', text, re.DOTALL)
        if json_match:
            return json_match.group()
        return text
    except:
        return text

def get_fallback_response():
    """Return a fallback response when AI fails."""
    return {
        "grammar_score": 75,
        "coherence_score": 70,
        "vocabulary_score": 65,
        "overall_score": 70,
        "summary": "AI evaluation service is temporarily unavailable. Using fallback scores.",
        "suggestions": [
            "Check your grammar and sentence structure",
            "Improve vocabulary variety",
            "Ensure proper punctuation"
        ]
    }

@app.route("/evaluate_writing", methods=["POST", "GET"])
def evaluate_writing():
    try:
        print("🎯 Received request at /evaluate_writing")
        
        if request.method == 'GET':
            return jsonify({
                "status": "Writing Evaluation API is running!",
                "endpoints": {
                    "POST /evaluate_writing": "Evaluate writing samples"
                }
            })
        
        # Handle POST request
        data = request.get_json()
        if not data:
            print("❌ No JSON data received")
            return jsonify({"error": "No JSON data received"}), 400
            
        writing = data.get('text', '')
        profile_id = data.get('profile_id')

        print(f"📝 Received writing for evaluation (Profile: {profile_id})")
        print(f"Text sample: {writing[:100]}...")

        if not writing.strip():
            return jsonify({"error": "No text received"}), 400

        # Query Ollama
        print("🤖 Sending to AI for evaluation...")
        result = ask_ollama(writing)

        # Add metadata
        result["timestamp"] = int(time.time())
        result["profile_id"] = profile_id

        print("✅ Evaluation complete!")
        return jsonify(result)

    except Exception as e:
        print("❌ Server Error in evaluate_writing:", e)
        print(traceback.format_exc())
        return jsonify({
            "error": "Server-side error",
            "grammar_score": 50,
            "coherence_score": 50, 
            "vocabulary_score": 50,
            "overall_score": 50,
            "summary": "Error during evaluation",
            "suggestions": ["Please try again later"]
        }), 500

@app.route("/generate_prompt", methods=["POST"])
def generate_prompt():
    try:
        data = request.get_json()
        profile = data.get("profile", {})

        age = profile.get("age_group", "adult")
        exam = profile.get("target_exam", "general")
        proficiency = profile.get("proficiency_self", "intermediate")
        learning_style = profile.get("learning_style", "visual")
        interests = ", ".join(profile.get("interests", []))

        prompt = f"""
You are an AI that creates personalized English writing tasks.

Use the following user profile to generate a writing task:

Age group: {age}
Target exam: {exam}
Proficiency: {proficiency}
Learning style: {learning_style}
Interests: {interests}

Rules:
- Return ONLY JSON.
- Never write explanations.
- Follow this exact JSON structure:

{{
  "writing_prompt": "<generated prompt>",
  "difficulty_level": "<easy|intermediate|advanced>"
}}
"""

        r = requests.post(
            "http://localhost:11434/api/generate",
            json={
                "model": "phi3:mini",
                "prompt": prompt,
                "stream": False
            },
            timeout=600
        )

        raw = r.json().get("response", "")
        cleaned = extract_json_from_text(raw)
        return jsonify(json.loads(cleaned))

    except Exception as e:
        print("❌ Error generating prompt:", e)
        return jsonify({
            "writing_prompt": "Write a short paragraph about your favorite hobby.",
            "difficulty_level": "easy"
        })

@app.route("/engagement_message_api", methods=["GET", "POST"])
def engagement_message_api():
    """
    Generate a short motivational / challenge message
    used by ../user_dashboard/update_engagement_logs.php
    """

    try:
        # GET / POST flexible
        if request.method == "POST":
            data = request.get_json() or {}
            prompt_text = data.get("prompt", "")
        else:
            prompt_text = request.args.get("prompt", "")

        if not prompt_text.strip():
            return jsonify({"error": "No prompt provided"}), 400

        print("🔥 Engagement Message Request Received")
        print("Prompt:", prompt_text[:150])

        # Prepare model prompt
        full_prompt = f"""
You are an AI that generates very short motivational or challenge messages
ONLY about the following learning activities:

- Courses
- Lessons
- Quizzes
- Flashcards
- Vocabulary (words)
- Writing practice and writing improvement

STRICT RULES:
- The message MUST relate 100% to the items listed above.
- NOTHING else is allowed. Do NOT talk about hobbies, life, fitness, mindset,
  emotions, mental health, personal life, meditation, family, productivity,
  money, discipline, motivation in general, or anything unrelated to this platform.
- No emojis.
- 1–2 short sentences only.
- Tone: supportive, specific, actionable.

Examples of VALID messages:
- "You're progressing well in lessons. Try completing one more to strengthen your basics."
- "Review your last quiz mistakes to improve your score next time."
- "Your vocabulary list is growing — practice five more words today."
- "Flashcards are a great habit. Try a quick review session."
- "Your writing is improving. Add 2–3 more sentences to practice coherence."

Examples of FORBIDDEN content:
- Anything about lifestyle, health, fitness, meditation, productivity,
  emotional support, or unrelated personal advice.

User activity data:
{prompt_text}
"""

        # Call Ollama
        r = requests.post(
            "http://localhost:11434/api/generate",
            json={
                "model": "phi3:mini",
                "prompt": full_prompt,
                "stream": False
            },
            timeout=30
        )

        r.raise_for_status()
        raw = r.json().get("response", "").strip()

        print("AI Output:", raw)

        # Return plain text (not JSON)
        return raw, 200

    except Exception as e:
        print("Engagement AI Error:", e)
        traceback.print_exc()
        return "Stay consistent! Even a few minutes a day helps you improve.", 200


@app.route("/health", methods=["GET"])
def health_check():
    """Health check endpoint"""
    try:
        # Test Ollama connection
        ollama_test = requests.get("http://localhost:11434/api/tags", timeout=5)
        ollama_status = "connected" if ollama_test.status_code == 200 else "disconnected"
        
        return jsonify({
            "status": "healthy",
            "service": "Writing Evaluation API",
            "ollama": ollama_status,
            "timestamp": int(time.time())
        })
    except:
        return jsonify({
            "status": "degraded", 
            "service": "Writing Evaluation API",
            "ollama": "disconnected",
            "timestamp": int(time.time())
        }), 500

if __name__ == "__main__":
    print("=" * 60)
    print("🔥 Writing Evaluation API starting...")
    print("📡 Port: 5002")
    print("🌐 URL: http://127.0.0.1:5002")
    print("=" * 60)
    
    # Test connections
    try:
        ollama_test = requests.get("http://localhost:11434/api/tags", timeout=5)
        if ollama_test.status_code == 200:
            print("✅ Ollama is connected and running")
        else:
            print("❌ Ollama responded with error")
    except Exception as e:
        print("❌ Cannot connect to Ollama:", e)
        print("   Make sure 'ollama serve' is running")

    print("🚀 Starting Flask server...")
    app.run(host='127.0.0.1', port=5002, debug=True)