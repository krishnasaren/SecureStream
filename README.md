# 🔐 Secure Streaming Platform

A secure video streaming platform running on **Apache Server**, built using **HTML, CSS, JavaScript, and PHP**, with a strong focus on **authentication, access control, and secure media delivery**.

This project prevents direct video downloads, unauthorized access, and common web vulnerabilities while ensuring smooth streaming performance.

---

## 📌 Project Goals

- Prevent direct access to video files
- Allow streaming only to authenticated users
- Protect against common web attacks
- Provide a clean, modular PHP architecture
- Run efficiently on Apache with PHP

---

## 🚀 Features

- 🔑 Session-based authentication system
- 🎥 Secure video streaming via PHP
- 🛡️ Protection against XSS, CSRF, path traversal
- 📁 Media files stored outside public directory
- 🔐 Security headers enforced
- 📡 Chunk-based streaming for large files
- 🚫 Directory listing disabled
- 📜 Clean and maintainable code structure

---

## 🧱 Tech Stack

| Layer | Technology |
|------|------------|
| Web Server | Apache |
| Backend | PHP |
| Frontend | HTML, CSS, JavaScript |
| Streaming | PHP file streaming |
| Security | Sessions, headers, validation |

---


## 🔐 Security Design

### Authentication
- PHP session-based authentication
- Session regenerated on login
- Unauthorized users redirected

### Authorization
- Every video request validated
- Access checks before streaming
- No direct file URLs exposed

### Secure Video Streaming
- Videos stored outside public root
- Streamed using PHP (`fopen`, `fread`)
- Supports large files via chunking
- Proper `Content-Type` headers set

### Input Validation
- All user input validated server-side
- Output escaped using `htmlspecialchars()`
- Prevents XSS and injection attacks

### File Protection
- Path traversal protection
- MIME type verification
- File existence and permission checks

---

## 🔐 HTTP Security Headers

Enabled via Apache / PHP:
- `X-Frame-Options: SAMEORIGIN`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Content-Security-Policy`
- `Strict-Transport-Security`

---

## ⚙️ Installation & Setup

### 1️⃣ Clone Repository
```bash
git clone https://github.com/krishnasaren/SecureStream.git
cd secure-streaming


```
define('BASE_URL', 'http://localhost/secure-streaming');
define('VIDEO_PATH', '/absolute/path/to/storage/videos/');
define('SESSION_TIMEOUT', 3600);
```

## 📂 Project Structure
secure-streaming/
│
├── public/                         # Publicly accessible (DocumentRoot)
│   ├── index.php                   # Dashboard / video list
│   ├── login.php                   # Login page
│   ├── logout.php                  # Logout handler
│   ├── register.php                # (Optional) user registration
│   ├── player.php                  # Video player page
│   │
│   └── assets/
│       ├── css/
│       │   └── style.css
│       ├── js/
│       │   └── app.js
│       └── images/
│
├── includes/                       # Core backend logic (NOT public)
│   ├── config.php                  # Global config (paths, constants)
│   ├── db.php                      # Database connection
│   ├── auth.php                    # Authentication & sessions
│   ├── security.php                # Security helpers (headers, checks)
│   ├── helpers.php                 # Utility functions
│
├── stream/                         # Secure streaming endpoints
│   └── video.php                   # Streams video in chunks
│
├── storage/                        # NOT publicly accessible
│   └── videos/                     # Actual video files
│       ├── video1.mp4
│       └── video2.mp4
│
├── logs/                           # Logs (optional but recommended)
│   └── access.log
│
├── uploads/                        # Temporary uploads (if any)
│
├── .htaccess                       # Apache security rules
├── .gitignore
├── README.md
└── SECURITY.md                     # (Optional) security policy

