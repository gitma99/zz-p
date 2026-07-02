#!/usr/bin/env python3
import os
import sys
import requests

def log(level, message):
    from datetime import datetime
    print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] {level}: {message}", flush=True)

def main():
    if len(sys.argv) < 2:
        log("ERROR", "Missing file path argument")
        sys.exit(1)

    file_path = sys.argv[1]
    caption = sys.argv[2] if len(sys.argv) > 2 else ""

    token = os.environ.get("TELEGRAM_BOT_TOKEN")
    user_id = os.environ.get("TELEGRAM_USER_ID")

    if not token or not user_id:
        log("WARN", "TELEGRAM_BOT_TOKEN or TELEGRAM_USER_ID is not configured")
        sys.exit(1)

    if not os.path.exists(file_path):
        log("ERROR", f"File does not exist: {file_path}")
        sys.exit(1)

    url = f"https://api.telegram.org/bot{token}/sendDocument"
    
    try:
        log("INFO", f"Uploading {file_path} via Python requests...")
        with open(file_path, "rb") as f:
            files = {
                "document": (os.path.basename(file_path), f, "application/zip")
            }
            data = {
                "chat_id": user_id,
                "caption": caption,
                "parse_mode": "HTML"
            }
            response = requests.post(url, data=data, files=files, timeout=60)
            
        if response.status_code == 200:
            log("INFO", "Telegram document sent successfully")
            sys.exit(0)
        else:
            log("WARN", f"Telegram API failed with code {response.status_code}. Response: {response.text}")
            sys.exit(1)
            
    except Exception as e:
        log("ERROR", f"Exception during upload: {str(e)}")
        sys.exit(1)

if __name__ == "__main__":
    main()
