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