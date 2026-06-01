<?php
require_once 'config.php';

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// GET — public
if ($method === 'GET') {
    $profile = $db->query("SELECT * FROM profile LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    jsonResponse($profile ?: []);
}

// POST — admin only (update profile + optional photo upload)
if ($method === 'POST') {
    requireAuth();

    $name    = trim($_POST['name'] ?? '');
    $bio     = trim($_POST['bio'] ?? '');
    $tagline = trim($_POST['tagline'] ?? '');

    $current = $db->query("SELECT * FROM profile LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $photo   = $current['photo'] ?? '';

    if (!empty($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['photo'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','webp','gif']) && $file['size'] <= 5 * 1024 * 1024) {
            if (!is_dir(PROFILE_PATH)) mkdir(PROFILE_PATH, 0777, true);
            $filename = 'profile_' . time() . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], PROFILE_PATH . $filename)) {
                // Delete old photo
                if ($photo && file_exists(PROFILE_PATH . $photo)) unlink(PROFILE_PATH . $photo);
                $photo = $filename;
            }
        }
    }

    if ($current) {
        $db->prepare("UPDATE profile SET name=?, bio=?, tagline=?, photo=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")
           ->execute([$name ?: $current['name'], $bio, $tagline, $photo, $current['id']]);
    } else {
        $db->prepare("INSERT INTO profile (name, bio, tagline, photo) VALUES (?,?,?,?)")
           ->execute([$name, $bio, $tagline, $photo]);
    }

    jsonResponse(['message' => 'Profile updated', 'photo' => $photo]);
}

errorResponse('Method not allowed', 405);
