<?php
define('DB_PATH', __DIR__ . '/../database/artsphere.db');
define('UPLOAD_PATH', __DIR__ . '/../uploads/artworks/');
define('UPLOAD_URL', '../uploads/artworks/');
define('JWT_SECRET', 'artsphere_secret_key_change_in_production_2024');
define('ADMIN_EMAIL', 'jeramayabing@gmail.com');

// Email config - update with your Gmail App Password
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'jeramayabing@gmail.com');
define('SMTP_PASS', 'admin'); // <-- Replace with Gmail App Password

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function getDB() {
    static $db = null;
    if ($db === null) {
        $dir = dirname(DB_PATH);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $db = new PDO('sqlite:' . DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA journal_mode=WAL');
        initDB($db);
    }
    return $db;
}

function initDB($db) {
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS artworks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            description TEXT,
            category TEXT DEFAULT 'General',
            price REAL DEFAULT 0,
            available INTEGER DEFAULT 1,
            image_path TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            subject TEXT,
            message TEXT NOT NULL,
            artwork_id INTEGER,
            type TEXT DEFAULT 'general',
            is_read INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // Seed admin user
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([ADMIN_EMAIL]);
    if (!$stmt->fetch()) {
        $hash = password_hash('admin', PASSWORD_BCRYPT);
        $db->prepare("INSERT INTO users (email, password) VALUES (?, ?)")
           ->execute([ADMIN_EMAIL, $hash]);
    }
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit();
}

function errorResponse($msg, $code = 400) {
    jsonResponse(['error' => $msg], $code);
}

function generateToken($userId) {
    $payload = [
        'sub' => $userId,
        'iat' => time(),
        'exp' => time() + (24 * 3600)
    ];
    $header = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload_enc = base64_encode(json_encode($payload));
    $sig = hash_hmac('sha256', "$header.$payload_enc", JWT_SECRET, true);
    $sig_enc = base64_encode($sig);
    return "$header.$payload_enc.$sig_enc";
}

function verifyToken($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return false;
    [$header, $payload, $sig] = $parts;
    $expected = base64_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    if (!hash_equals($expected, $sig)) return false;
    $data = json_decode(base64_decode($payload), true);
    if ($data['exp'] < time()) return false;
    return $data;
}

function requireAuth() {
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    if (!$auth || !str_starts_with($auth, 'Bearer ')) {
        errorResponse('Unauthorized', 401);
    }
    $token = substr($auth, 7);
    $data = verifyToken($token);
    if (!$data) errorResponse('Invalid or expired token', 401);
    return $data;
}
