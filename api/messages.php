<?php
require_once 'config.php';

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// POST - send message (public)
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);

    $name = trim($body['name'] ?? '');
    $email = trim($body['email'] ?? '');
    $subject = trim($body['subject'] ?? 'New Message from ArtSphere');
    $message = trim($body['message'] ?? '');
    $artwork_id = isset($body['artwork_id']) ? (int)$body['artwork_id'] : null;
    $type = $body['type'] ?? 'general'; // 'general', 'commission', 'purchase'

    if (!$name || !$email || !$message) {
        errorResponse('Name, email, and message are required');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        errorResponse('Invalid email address');
    }

    // Save to DB
    $stmt = $db->prepare("INSERT INTO messages (name, email, subject, message, artwork_id, type) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$name, $email, $subject, $message, $artwork_id, $type]);
    $msgId = $db->lastInsertId();

    // Get artwork info if applicable
    $artworkInfo = '';
    if ($artwork_id) {
        $artStmt = $db->prepare("SELECT title, price FROM artworks WHERE id = ?");
        $artStmt->execute([$artwork_id]);
        $art = $artStmt->fetch(PDO::FETCH_ASSOC);
        if ($art) {
            $artworkInfo = "\n\nRegarding Artwork: {$art['title']}" . ($art['price'] > 0 ? " (₱" . number_format($art['price'], 2) . ")" : "");
        }
    }

    // Send email via Gmail SMTP (using PHP's mail or socket)
    $emailSent = sendEmail(
        ADMIN_EMAIL,
        "ArtSphere: $subject",
        buildEmailHTML($name, $email, $subject, $message, $type, $artworkInfo),
        $email,
        $name
    );

    jsonResponse([
        'id' => $msgId,
        'message' => 'Message sent successfully',
        'email_sent' => $emailSent
    ], 201);
}

// GET - admin only, fetch messages
if ($method === 'GET') {
    requireAuth();

    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;
    $unread = isset($_GET['unread']);

    $where = $unread ? 'WHERE is_read = 0' : '';

    $total = $db->query("SELECT COUNT(*) FROM messages $where")->fetchColumn();
    $stmt = $db->prepare("SELECT m.*, a.title as artwork_title FROM messages m
                          LEFT JOIN artworks a ON m.artwork_id = a.id
                          $where ORDER BY m.created_at DESC LIMIT ? OFFSET ?");
    $stmt->execute([$limit, $offset]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $unreadCount = $db->query("SELECT COUNT(*) FROM messages WHERE is_read = 0")->fetchColumn();

    jsonResponse([
        'messages' => $messages,
        'total' => (int)$total,
        'unread' => (int)$unreadCount,
        'page' => $page,
        'pages' => ceil($total / $limit)
    ]);
}

// PATCH - mark as read
if ($method === 'PATCH') {
    requireAuth();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) errorResponse('ID required');
    $db->prepare("UPDATE messages SET is_read = 1 WHERE id = ?")->execute([$id]);
    jsonResponse(['message' => 'Marked as read']);
}

// DELETE - admin only
if ($method === 'DELETE') {
    requireAuth();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) errorResponse('ID required');
    $db->prepare("DELETE FROM messages WHERE id = ?")->execute([$id]);
    jsonResponse(['message' => 'Message deleted']);
}

function buildEmailHTML($name, $email, $subject, $message, $type, $artworkInfo) {
    $typeLabel = match($type) {
        'commission' => '🎨 Commission Request',
        'purchase' => '🛒 Purchase Inquiry',
        default => '✉️ General Message'
    };
    $time = date('F j, Y \a\t g:i A');
    return "
<!DOCTYPE html>
<html>
<head><meta charset='utf-8'></head>
<body style='font-family: Georgia, serif; max-width: 600px; margin: 0 auto; background: #fff8f3;'>
  <div style='background: #E8825A; padding: 30px; text-align: center;'>
    <h1 style='color: white; margin: 0; font-size: 28px; letter-spacing: 2px;'>ArtSphere</h1>
    <p style='color: rgba(255,255,255,0.85); margin: 5px 0 0; font-size: 13px;'>New Message Received</p>
  </div>
  <div style='padding: 30px; background: white;'>
    <div style='background: #fff8f3; border-left: 4px solid #E8825A; padding: 12px 16px; margin-bottom: 24px;'>
      <strong style='color: #E8825A;'>$typeLabel</strong>
    </div>
    <table style='width: 100%; border-collapse: collapse;'>
      <tr><td style='padding: 8px 0; color: #999; width: 100px;'>From</td>
          <td style='padding: 8px 0; font-weight: bold; color: #1a1a1a;'>$name</td></tr>
      <tr><td style='padding: 8px 0; color: #999;'>Email</td>
          <td style='padding: 8px 0;'><a href='mailto:$email' style='color: #E8825A;'>$email</a></td></tr>
      <tr><td style='padding: 8px 0; color: #999;'>Subject</td>
          <td style='padding: 8px 0; color: #1a1a1a;'>$subject</td></tr>
      <tr><td style='padding: 8px 0; color: #999;'>Time</td>
          <td style='padding: 8px 0; color: #666;'>$time</td></tr>
    </table>
    " . ($artworkInfo ? "<div style='background: #f9f9f9; border-radius: 6px; padding: 12px; margin: 16px 0; color: #555; font-size: 14px;'>$artworkInfo</div>" : "") . "
    <div style='margin-top: 24px;'>
      <p style='color: #999; font-size: 13px; margin-bottom: 8px;'>Message:</p>
      <div style='background: #fafafa; border-radius: 8px; padding: 20px; color: #333; line-height: 1.7; white-space: pre-wrap;'>$message</div>
    </div>
    <div style='margin-top: 24px; text-align: center;'>
      <a href='mailto:$email?subject=Re: $subject' style='background: #E8825A; color: white; padding: 12px 28px; border-radius: 4px; text-decoration: none; font-size: 14px;'>Reply to $name</a>
    </div>
  </div>
  <div style='padding: 20px; text-align: center; color: #bbb; font-size: 12px;'>
    ArtSphere — Personal Art Archive &nbsp;·&nbsp; This message was sent via your contact form
  </div>
</body>
</html>";
}

function sendEmail($to, $subject, $htmlBody, $replyTo = '', $replyToName = '') {
    // Use PHPMailer-style SMTP via socket (no library needed)
    // If SMTP_PASS is not set, fall back to PHP mail()
    if (SMTP_PASS === 'YOUR_GMAIL_APP_PASSWORD') {
        // Fallback: native PHP mail (works on most hosts)
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: ArtSphere <" . SMTP_USER . ">\r\n";
        if ($replyTo) $headers .= "Reply-To: $replyToName <$replyTo>\r\n";
        return mail($to, $subject, $htmlBody, $headers);
    }

    // SMTP via socket
    try {
        $socket = fsockopen('ssl://' . SMTP_HOST, 465, $errno, $errstr, 10);
        if (!$socket) return false;

        $read = fgets($socket, 512);
        fputs($socket, "EHLO artsphere.local\r\n");
        while ($line = fgets($socket, 512)) { if (substr($line, 3, 1) == ' ') break; }

        fputs($socket, "AUTH LOGIN\r\n"); fgets($socket, 512);
        fputs($socket, base64_encode(SMTP_USER) . "\r\n"); fgets($socket, 512);
        fputs($socket, base64_encode(SMTP_PASS) . "\r\n"); $authResp = fgets($socket, 512);

        if (strpos($authResp, '235') === false) { fclose($socket); return false; }

        fputs($socket, "MAIL FROM:<" . SMTP_USER . ">\r\n"); fgets($socket, 512);
        fputs($socket, "RCPT TO:<$to>\r\n"); fgets($socket, 512);
        fputs($socket, "DATA\r\n"); fgets($socket, 512);

        $replyHeader = $replyTo ? "Reply-To: $replyToName <$replyTo>\r\n" : '';
        $msg = "From: ArtSphere <" . SMTP_USER . ">\r\n"
             . "To: $to\r\n"
             . "Subject: $subject\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/html; charset=UTF-8\r\n"
             . $replyHeader
             . "\r\n"
             . $htmlBody . "\r\n.\r\n";

        fputs($socket, $msg);
        $resp = fgets($socket, 512);
        fputs($socket, "QUIT\r\n");
        fclose($socket);
        return strpos($resp, '250') !== false;
    } catch (Exception $e) {
        return false;
    }
}

errorResponse('Method not allowed', 405);
