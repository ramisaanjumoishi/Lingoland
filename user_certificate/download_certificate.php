<?php
// Ensure img parameter exists
if (!isset($_GET['img'])) {
    die("No certificate specified.");
}

$path = $_GET['img'];

// Security: prevent directory traversal
$path = str_replace(["../", "./"], "", $path);

// Build the safe path (relative to your project root)
$fullPath = __DIR__ . "/../" . $path;

// If file does NOT exist, stop
if (!file_exists($fullPath)) {
    die("File not found: " . htmlspecialchars($path));
}

// Detect file type for correct headers
$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$mime = "application/octet-stream";

if ($ext === "jpg" || $ext === "jpeg") $mime = "image/jpeg";
if ($ext === "png") $mime = "image/png";
if ($ext === "webp") $mime = "image/webp";

// Force download
header("Content-Type: $mime");
header("Content-Disposition: attachment; filename=certificate.$ext");
header("Content-Length: " . filesize($fullPath));
readfile($fullPath);
exit;
?>
