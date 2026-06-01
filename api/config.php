<?php
ini_set('display_errors', 0);
error_reporting(0);
// Support Railway volume or fallback to local paths
$dataDir = getenv('RAILWAY_VOLUME_MOUNT_PATH') ?: __DIR__ . '/..';

define('DB_PATH',     $dataDir . '/database/artsphere.db');
define('UPLOAD_PATH', $dataDir . '/uploads/artworks/');
define('PROFILE_PATH', $dataDir . '/uploads/profile/');
define('UPLOAD_URL',  '/uploads/artworks/');
define('PROFILE_URL', '/uploads/profile/');
define('JWT_SECRET',  getenv('JWT_SECRET') ?: 'artsphere_jwt_secret_2024');
define('ADMIN_EMAIL', 'jeramayabing@gmail.com');

define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 465);
define('SMTP_USER', 'jeramayabing@gmail.com');
define('SMTP_PASS', getenv('GMAIL_APP_PASSWORD') ?: '');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

function getDB() {
    static $db = null;
    if ($db === null) {
        $dir = dirname(DB_PATH);
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        if (!is_dir(UPLOAD_PATH)) mkdir(UPLOAD_PATH, 0777, true);
        if (!is_dir(PROFILE_PATH)) mkdir(PROFILE_PATH, 0777, true);
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
        CREATE TABLE IF NOT EXISTS profile (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT DEFAULT 'The Artist',
            bio TEXT DEFAULT '',
            tagline TEXT DEFAULT '',
            photo TEXT DEFAULT '',
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // Seed admin
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([ADMIN_EMAIL]);
    if (!$stmt->fetch()) {
        $db->prepare("INSERT INTO users (email, password) VALUES (?, ?)")
           ->execute([ADMIN_EMAIL, password_hash('admin', PASSWORD_BCRYPT)]);
    }

    // Seed profile row
    $p = $db->query("SELECT id FROM profile LIMIT 1")->fetch();
    if (!$p) {
        $db->exec("INSERT INTO profile (name, bio, tagline) VALUES ('The Artist', 'Welcome to my art archive.', 'Creating moments into art')");
    }
}

function jsonResponse($data, $code = 200) { http_response_code($code); echo json_encode($data); exit(); }
function errorResponse($msg, $code = 400) { jsonResponse(['error' => $msg], $code); }

function generateToken($userId) {
    $header  = base64_encode(json_encode(['alg'=>'HS256','typ'=>'JWT']));
    $payload = base64_encode(json_encode(['sub'=>$userId,'iat'=>time(),'exp'=>time()+86400]));
    $sig     = base64_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    return "$header.$payload.$sig";
}

function verifyToken($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return false;
    [$h, $p, $s] = $parts;
    $expected = base64_encode(hash_hmac('sha256', "$h.$p", JWT_SECRET, true));
    if (!hash_equals($expected, $s)) return false;
    $data = json_decode(base64_decode($p), true);
    if ($data['exp'] < time()) return false;
    return $data;
}

function requireAuth() {
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    if (!$auth || !str_starts_with($auth, 'Bearer ')) errorResponse('Unauthorized', 401);
    $data = verifyToken(substr($auth, 7));
    if (!$data) errorResponse('Invalid or expired token', 401);
    return $data;
}

function sendEmail($to, $subject, $htmlBody, $replyTo = '', $replyToName = '') {
    if (!SMTP_PASS) {
        $headers  = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: ArtSphere <" . SMTP_USER . ">\r\n";
        if ($replyTo) $headers .= "Reply-To: $replyToName <$replyTo>\r\n";
        return @mail($to, $subject, $htmlBody, $headers);
    }
    try {
        $socket = @fsockopen('ssl://smtp.gmail.com', 465, $errno, $errstr, 10);
        if (!$socket) return false;
        fgets($socket, 512);
        fputs($socket, "EHLO artsphere\r\n");
        while ($l = fgets($socket, 512)) { if ($l[3] === ' ') break; }
        fputs($socket, "AUTH LOGIN\r\n"); fgets($socket, 512);
        fputs($socket, base64_encode(SMTP_USER) . "\r\n"); fgets($socket, 512);
        fputs($socket, base64_encode(SMTP_PASS) . "\r\n");
        $auth = fgets($socket, 512);
        if (strpos($auth, '235') === false) { fclose($socket); return false; }
        fputs($socket, "MAIL FROM:<" . SMTP_USER . ">\r\n"); fgets($socket, 512);
        fputs($socket, "RCPT TO:<$to>\r\n"); fgets($socket, 512);
        fputs($socket, "DATA\r\n"); fgets($socket, 512);
        $rh = $replyTo ? "Reply-To: $replyToName <$replyTo>\r\n" : '';
        fputs($socket, "From: ArtSphere <" . SMTP_USER . ">\r\nTo: $to\r\nSubject: $subject\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n{$rh}\r\n$htmlBody\r\n.\r\n");
        $r = fgets($socket, 512);
        fputs($socket, "QUIT\r\n");
        fclose($socket);
        return strpos($r, '250') !== false;
    } catch (Exception $e) { return false; }
}
