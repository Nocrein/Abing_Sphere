<?php
ini_set('display_errors', 0);
error_reporting(0);
require_once 'config.php';
requireAuth();

$db = getDB();

$totalArtworks = $db->query("SELECT COUNT(*) FROM artworks")->fetchColumn();
$totalMessages = $db->query("SELECT COUNT(*) FROM messages")->fetchColumn();
$unreadMessages = $db->query("SELECT COUNT(*) FROM messages WHERE is_read = 0")->fetchColumn();
$categories = $db->query("SELECT COUNT(DISTINCT category) FROM artworks")->fetchColumn();
$recentMessages = $db->query("SELECT name, email, subject, type, created_at FROM messages ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
$recentArtworks = $db->query("SELECT id, title, category, image_path, created_at FROM artworks ORDER BY created_at DESC LIMIT 4")->fetchAll(PDO::FETCH_ASSOC);

jsonResponse([
    'total_artworks' => (int)$totalArtworks,
    'total_messages' => (int)$totalMessages,
    'unread_messages' => (int)$unreadMessages,
    'categories' => (int)$categories,
    'recent_messages' => $recentMessages,
    'recent_artworks' => $recentArtworks,
]);
