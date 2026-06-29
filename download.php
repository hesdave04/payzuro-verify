<?php
// download.php — Download all video clips for a session as a ZIP
$base_dir = 'records';

if (!isset($_GET['dir'])) {
    http_response_code(400);
    echo 'Missing dir parameter';
    exit;
}

$dir = $_GET['dir'];
$target_dir = realpath($base_dir . '/' . $dir);

// Security: prevent directory traversal
if (!$target_dir || strpos($target_dir, realpath($base_dir)) !== 0 || !is_dir($target_dir)) {
    http_response_code(404);
    echo 'Session not found';
    exit;
}

// Decode session date from hex uniqid
function uniqid_to_date($hex) {
    $ts = hexdec(substr($hex, 0, 8));
    if ($ts > 1577836800 && $ts < 1893456000) {
        return date('Y-m-d', $ts);
    }
    return 'unknown';
}

$session_date = uniqid_to_date($dir);
$zip_filename = "session_{$dir}_{$session_date}.zip";

// Collect non-zero webm files
$files = [];
if ($handle = opendir($target_dir)) {
    while (false !== ($entry = readdir($handle))) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $target_dir . '/' . $entry;
        if (is_file($path) && filesize($path) > 0) {
            // Decode timestamp for the filename
            $base_name = pathinfo($entry, PATHINFO_FILENAME);
            $ext = pathinfo($entry, PATHINFO_EXTENSION);
            $hex_ts = hexdec(substr($base_name, 0, 8));
            $dated_name = '';
            if ($hex_ts > 1577836800 && $hex_ts < 1893456000) {
                $dated_name = date('Y-m-d_H-i-s', $hex_ts) . '_' . $base_name . '.' . $ext;
            } else {
                $dated_name = $entry;
            }
            $files[] = ['path' => $path, 'name' => $dated_name, 'mtime' => filemtime($path)];
        }
    }
    closedir($handle);
}

if (empty($files)) {
    http_response_code(404);
    echo 'No video clips found';
    exit;
}

// Sort by modification time
usort($files, function($a, $b) { return $a['mtime'] - $b['mtime']; });

// Create ZIP
$zip = new ZipArchive();
$tmp_file = tempnam(sys_get_temp_dir(), 'session_');

if ($zip->open($tmp_file, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    echo 'Failed to create ZIP';
    exit;
}

foreach ($files as $f) {
    $zip->addFile($f['path'], $f['name']);
}
$zip->close();

// Send ZIP
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
header('Content-Length: ' . filesize($tmp_file));
header('Cache-Control: no-cache');

readfile($tmp_file);
unlink($tmp_file);
exit;
?>
