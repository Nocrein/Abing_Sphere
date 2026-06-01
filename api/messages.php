<?php
require_once 'config.php';

// Suppress PHP notices/warnings from polluting JSON output
ini_set('display_errors', 0);
error_reporting(0);

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// ── POST — send message (public) ──────────────────────────
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) errorResponse('Invalid request body');

    $name       = trim($body['name']    ?? '');
    $email      = trim($body['email']   ?? '');
    $subject    = trim($body['subject'] ?? 'New Message from ArtSphere');
    $message    = trim($body['message'] ?? '');
    $artwork_id = isset($body['artwork_id']) && $body['artwork_id'] ? (int)$body['artwork_id'] : null;
    $type       = in_array($body['type'] ?? '', ['general','commission','purchase'])
                  ? $body['type'] : 'general';

    if (!$name || !$email || !$message) {
        errorResponse('Name, email, and message are required');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        errorResponse('Invalid email address');
    }

    // Save to DB first — even if email fails, message is stored
    $stmt = $db->prepare(
        "INSERT INTO messages (name, email, subject, message, artwork_id, type) VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$name, $email, $subject, $message, $artwork_id, $type]);
    $msgId = $db->lastInsertId();

    // Artwork context for email
    $artworkLine = '';
    if ($artwork_id) {
        $artStmt = $db->prepare("SELECT title, price FROM artworks WHERE id = ?");
        $artStmt->execute([$artwork_id]);
        $art = $artStmt->fetch(PDO::FETCH_ASSOC);
        if ($art) {
            $artworkLine = "Regarding: {$art['title']}"
                . ($art['price'] > 0 ? " (₱" . number_format($art['price'], 2) . ")" : "");
        }
    }

    // Try to send email — failure is non-fatal
    $emailSent = false;
    try {
        $emailSent = sendEmail(
            ADMIN_EMAIL,
            "ArtSphere: $subject",
            buildEmailHTML($name, $email, $subject, $message, $type, $artworkLine),
            $email,
            $name
        );
    } catch (Throwable $e) {
        // Email failed silently — message already saved to DB
    }

    jsonResponse([
        'id'         => (int)$msgId,
        'message'    => 'Message sent successfully',
        'email_sent' => $emailSent
    ], 201);
}

// ── GET — admin only ──────────────────────────────────────
if ($method === 'GET') {
    requireAuth();
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = 20;
    $offset = ($page - 1) * $limit;

    $total = $db->query("SELECT COUNT(*) FROM messages")->fetchColumn();
    $stmt  = $db->prepare(
        "SELECT m.*, a.title as artwork_title
         FROM messages m
         LEFT JOIN artworks a ON m.artwork_id = a.id
         ORDER BY m.created_at DESC LIMIT ? OFFSET ?"
    );
    $stmt->execute([$limit, $offset]);
    $messages    = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $unreadCount = $db->query("SELECT COUNT(*) FROM messages WHERE is_read = 0")->fetchColumn();

    jsonResponse([
        'messages' => $messages,
        'total'    => (int)$total,
        'unread'   => (int)$unreadCount,
        'page'     => $page,
        'pages'    => (int)ceil($total / $limit)
    ]);
}

// ── PATCH — mark as read ──────────────────────────────────
if ($method === 'PATCH') {
    requireAuth();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) errorResponse('ID required');
    $db->prepare("UPDATE messages SET is_read = 1 WHERE id = ?")->execute([$id]);
    jsonResponse(['message' => 'Marked as read']);
}

// ── DELETE — admin only ───────────────────────────────────
if ($method === 'DELETE') {
    requireAuth();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) errorResponse('ID required');
    $db->prepare("DELETE FROM messages WHERE id = ?")->execute([$id]);
    jsonResponse(['message' => 'Message deleted']);
}

errorResponse('Method not allowed', 405);

// ── HELPERS ───────────────────────────────────────────────
function buildEmailHTML($name, $email, $subject, $message, $type, $artworkLine = '') {
    $typeLabel = match($type) {
        'commission' => '🎨 Commission Request',
        'purchase'   => '🛒 Purchase Inquiry',
        default      => '✉️ General Message'
    };
    $time        = date('F j, Y \a\t g:i A');
    $artworkHTML = $artworkLine
        ? "<div style='background:#fff3ec;border-radius:6px;padding:10px 14px;margin:16px 0;color:#E8825A;font-size:14px;'>$artworkLine</div>"
        : '';
    $msgEsc = nl2br(htmlspecialchars($message));

    return "<!DOCTYPE html><html><head><meta charset='utf-8'></head>
<body style='font-family:Georgia,serif;max-width:600px;margin:0 auto;background:#fff8f3;'>
  <div style='background:#E8825A;padding:28px;text-align:center;'>
    <h1 style='color:white;margin:0;font-size:26px;letter-spacing:2px;'>ArtSphere</h1>
    <p style='color:rgba(255,255,255,.8);margin:4px 0 0;font-size:13px;'>New Message Received</p>
  </div>
  <div style='padding:28px;background:white;'>
    <div style='background:#fff8f3;border-left:4px solid #E8825A;padding:10px 16px;margin-bottom:20px;'>
      <strong style='color:#E8825A;'>$typeLabel</strong>
    </div>
    <table style='width:100%;border-collapse:collapse;font-size:14px;'>
      <tr><td style='padding:7px 0;color:#999;width:80px;'>From</td><td style='padding:7px 0;font-weight:bold;'>$name</td></tr>
      <tr><td style='padding:7px 0;color:#999;'>Email</td><td style='padding:7px 0;'><a href='mailto:$email' style='color:#E8825A;'>$email</a></td></tr>
      <tr><td style='padding:7px 0;color:#999;'>Subject</td><td style='padding:7px 0;'>$subject</td></tr>
      <tr><td style='padding:7px 0;color:#999;'>Time</td><td style='padding:7px 0;color:#666;'>$time</td></tr>
    </table>
    $artworkHTML
    <div style='margin-top:20px;'>
      <p style='color:#999;font-size:12px;margin-bottom:8px;text-transform:uppercase;letter-spacing:.06em;'>Message</p>
      <div style='background:#fafafa;border-radius:8px;padding:18px;color:#333;line-height:1.7;'>$msgEsc</div>
    </div>
    <div style='margin-top:24px;text-align:center;'>
      <a href='mailto:$email?subject=Re: " . rawurlencode($subject) . "' style='background:#E8825A;color:white;padding:12px 28px;border-radius:4px;text-decoration:none;font-size:14px;'>Reply to $name</a>
    </div>
  </div>
  <div style='padding:18px;text-align:center;color:#bbb;font-size:12px;'>
    ArtSphere &nbsp;·&nbsp; Sent via contact form
  </div>
</body></html>";
}
