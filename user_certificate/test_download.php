<?php
if (!isset($_GET['img'])) {
    die("No image specified.");
}

$imgPath = $_GET['img'];
$imgPath = preg_replace('#(\.\./)+#', '../', $imgPath);

if (!str_starts_with($imgPath, '../')) {
    $imgPath = '../' . $imgPath;
}

if (!file_exists($imgPath)) {
    die("File not found: " . htmlspecialchars($imgPath));
}

// Get file info
$fileName = basename($imgPath);
$fileSize = filesize($imgPath);

// Set headers for download
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . $fileSize);
header('Cache-Control: must-revalidate');
header('Pragma: public');

// Output the file
readfile($imgPath);
exit();
?>