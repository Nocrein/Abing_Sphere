<?php
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Strip trailing slashes (except root)
if ($uri !== '/' && str_ends_with($uri, '/')) {
    $uri = rtrim($uri, '/');
}

// Serve API files
if (str_starts_with($uri, '/api/')) {
    $file = __DIR__ . $uri;
    if (is_file($file)) { require $file; return true; }
    header('Content-Type: application/json');
    http_response_code(404);
    echo json_encode(['error' => 'API endpoint not found']);
    return true;
}

// Serve uploads (artworks + profile photos)
if (str_starts_with($uri, '/uploads/')) {
    $dataDir = getenv('RAILWAY_VOLUME_MOUNT_PATH') ?: __DIR__;
    $file = $dataDir . $uri;
    if (is_file($file)) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = match($ext) {
            'jpg','jpeg' => 'image/jpeg',
            'png'        => 'image/png',
            'webp'       => 'image/webp',
            'gif'        => 'image/gif',
            default      => 'application/octet-stream'
        };
        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=31536000');
        readfile($file);
        return true;
    }
    http_response_code(404);
    echo 'Not found';
    return true;
}

// Serve static assets (css, js, fonts, etc.)
if (is_file(__DIR__ . $uri) && $uri !== '/') {
    return false;
}

// Admin pages
if (str_starts_with($uri, '/admin/')) {
    $file = __DIR__ . $uri;
    if (is_file($file)) return false;
    // Fallback to dashboard
    require __DIR__ . '/admin/dashboard.html';
    return true;
}

// Everything else → SPA index
require __DIR__ . '/index.html';
return true;
