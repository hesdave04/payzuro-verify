<?php
define('BASE_URL', 'https://verify.payzuro.com/records/');  // Set to your base URL
$base_dir = 'records';  // Local path to the base directory

// Compute the current directory based on input or default to the base directory
$current_dir = $base_dir;
if (isset($_GET['dir']) && is_dir($base_dir . '/' . $_GET['dir'])) {
    $current_dir = realpath($base_dir . '/' . $_GET['dir']);
    // Prevent navigating above the base directory
    if (strpos($current_dir, realpath($base_dir)) !== 0) {
        $current_dir = $base_dir;
    }
}

// List all files and directories in the current directory
echo "<ul>";
if ($handle = opendir($current_dir)) {
    while (false !== ($entry = readdir($handle))) {
        if ($entry != "." && $entry != "..") {
            $full_path = $current_dir . '/' . $entry;
            $relative_path = substr($full_path, strlen($base_dir) + 1);
            if (is_dir($full_path)) {
                echo "<li><a href='?dir=" . urlencode($relative_path) . "'>$entry</a></li>";
            } else {
                // Only show files larger than 0 bytes
                if (filesize($full_path) > 0) {
                    // Use BASE_URL for the file links
                    $filename = basename($relative_path);
                    $last_directory = basename(dirname($relative_path));
                    echo "<li><a href='" . BASE_URL . $last_directory . "/" . $filename . "' target='_blank'>$entry</a></li>";
                }
            }
        }
    }
    closedir($handle);
}
echo "</ul>";
?>
