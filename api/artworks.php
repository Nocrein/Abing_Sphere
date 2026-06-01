<?php
ini_set('display_errors', 0);
error_reporting(0);
require_once 'config.php';

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

// GET - public
if ($method === 'GET') {
    if ($id) {
        $stmt = $db->prepare("SELECT * FROM artworks WHERE id = ?");
        $stmt->execute([$id]);
        $art = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$art) errorResponse('Artwork not found', 404);
        jsonResponse($art);
    }

    $category = $_GET['category'] ?? null;
    $search = $_GET['search'] ?? null;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 12;
    $offset = ($page - 1) * $limit;

    $where = [];
    $params = [];

    if ($category && $category !== 'All') {
        $where[] = "category = ?";
        $params[] = $category;
    }
    if ($search) {
        $where[] = "(title LIKE ? OR description LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $db->prepare("SELECT COUNT(*) FROM artworks $whereSQL");
    $countStmt->execute($params);
    $total = $countStmt->fetchColumn();

    $stmt = $db->prepare("SELECT * FROM artworks $whereSQL ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $stmt->execute([...$params, $limit, $offset]);
    $artworks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get categories
    $catStmt = $db->query("SELECT DISTINCT category FROM artworks ORDER BY category");
    $categories = array_column($catStmt->fetchAll(PDO::FETCH_ASSOC), 'category');

    jsonResponse([
        'artworks' => $artworks,
        'total' => (int)$total,
        'page' => $page,
        'pages' => ceil($total / $limit),
        'categories' => $categories
    ]);
}

// POST - admin only
if ($method === 'POST') {
    requireAuth();

    if (empty($_FILES['image'])) {
        errorResponse('Image is required');
    }

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category = trim($_POST['category'] ?? 'General');
    $price = (float)($_POST['price'] ?? 0);
    $available = isset($_POST['available']) ? (int)$_POST['available'] : 1;

    if (!$title) errorResponse('Title is required');

    $file = $_FILES['image'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if (!in_array($ext, $allowed)) errorResponse('Invalid file type');
    if ($file['size'] > 10 * 1024 * 1024) errorResponse('File too large (max 10MB)');

    if (!is_dir(UPLOAD_PATH)) mkdir(UPLOAD_PATH, 0755, true);

    $filename = uniqid('art_') . '.' . $ext;
    $dest = UPLOAD_PATH . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        errorResponse('Failed to save image');
    }

    $stmt = $db->prepare("INSERT INTO artworks (title, description, category, price, available, image_path)
                          VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$title, $description, $category, $price, $available, $filename]);

    jsonResponse(['id' => $db->lastInsertId(), 'message' => 'Artwork created'], 201);
}

// PUT - admin only
if ($method === 'PUT') {
    requireAuth();
    if (!$id) errorResponse('ID required');

    $stmt = $db->prepare("SELECT * FROM artworks WHERE id = ?");
    $stmt->execute([$id]);
    $art = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$art) errorResponse('Artwork not found', 404);

    // Handle multipart or JSON
    if (!empty($_FILES['image'])) {
        $body = $_POST;
    } else {
        parse_str(file_get_contents('php://input'), $body);
        if (!$body) $body = json_decode(file_get_contents('php://input'), true) ?? [];
    }

    $title = trim($body['title'] ?? $art['title']);
    $description = trim($body['description'] ?? $art['description']);
    $category = trim($body['category'] ?? $art['category']);
    $price = isset($body['price']) ? (float)$body['price'] : $art['price'];
    $available = isset($body['available']) ? (int)$body['available'] : $art['available'];
    $image_path = $art['image_path'];

    if (!empty($_FILES['image'])) {
        $file = $_FILES['image'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (in_array($ext, $allowed) && $file['size'] <= 10 * 1024 * 1024) {
            $filename = uniqid('art_') . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], UPLOAD_PATH . $filename)) {
                // Delete old
                if (file_exists(UPLOAD_PATH . $art['image_path'])) {
                    unlink(UPLOAD_PATH . $art['image_path']);
                }
                $image_path = $filename;
            }
        }
    }

    $db->prepare("UPDATE artworks SET title=?, description=?, category=?, price=?, available=?, image_path=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")
       ->execute([$title, $description, $category, $price, $available, $image_path, $id]);

    jsonResponse(['message' => 'Artwork updated']);
}

// DELETE - admin only
if ($method === 'DELETE') {
    requireAuth();
    if (!$id) errorResponse('ID required');

    $stmt = $db->prepare("SELECT * FROM artworks WHERE id = ?");
    $stmt->execute([$id]);
    $art = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$art) errorResponse('Artwork not found', 404);

    if (file_exists(UPLOAD_PATH . $art['image_path'])) {
        unlink(UPLOAD_PATH . $art['image_path']);
    }

    $db->prepare("DELETE FROM artworks WHERE id = ?")->execute([$id]);
    jsonResponse(['message' => 'Artwork deleted']);
}

errorResponse('Method not allowed', 405);
