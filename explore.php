<?php
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
        if ($entry != "." && $entry != ".." && $entry != "account.json") {
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
if (!$is_root) {
    $account_info = load_session_account($current_dir);
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
    .subtitle { color: #8b949e; margin-bottom: 4px; font-size: 0.9rem; }
    .account-badge { display: inline-block; background: #1f6feb33; color: #58a6ff; padding: 3px 10px; border-radius: 12px; font-size: 0.8rem; margin-bottom: 12px; }
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
    if ($dir_ts) echo '<p class="subtitle">Started: ' . date('M j, Y \a\t g:i A', $dir_ts) . ' UTC</p>';
    echo '<p class="subtitle">' . $clip_count . ' video clips</p>';
    if ($account_info) echo '<span class="account-badge">👤 ' . htmlspecialchars($account_info['email'] ?? 'Unknown') . '</span>';
    ?>
    
    <?php if ($clip_count > 0): ?>
    <div class="actions">
        <a href="download.php?dir=<?php echo urlencode(basename($current_dir)); ?>" class="btn btn-primary">
            📦 Download All (ZIP)
        </a>
        <button id="combineBtn" class="btn btn-secondary" onclick="combineVideos()">
            🎬 Combine & Download
        </button>
    </div>
    <div class="progress-bar" id="progressBar"><div class="fill" id="progressFill"></div></div>
    <div class="status-msg" id="statusMsg"></div>
    <?php endif; ?>
<?php endif; ?>

<?php if (empty($entries)): ?>
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
        $account_label = $sess_account ? ' — ' . htmlspecialchars($sess_account['email'] ?? '') : '';
        // Count real clips in this session (>= MIN_CLIP_SIZE)
        $sess_clips = 0;
        $sess_path = realpath($base_dir) . '/' . $e['name'];
        if ($dh = @opendir($sess_path)) {
            while (($f = readdir($dh)) !== false) {
                if ($f !== '.' && $f !== '..' && $f !== 'account.json' && is_file($sess_path.'/'.$f) && filesize($sess_path.'/'.$f) >= MIN_CLIP_SIZE) $sess_clips++;
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
        // Step 1: Download all clip data as ArrayBuffers
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

        // Step 2: Find the best clip for metadata (largest file = most likely to work)
        const sortedBySize = [...buffers].sort((a, b) => b.byteLength - a.byteLength);
        const metaBlob = new Blob([sortedBySize[0]], { type: 'video/webm' });

        // Try to determine a working mimeType for MediaRecorder
        const mimeTypes = [
            'video/webm;codecs=vp8,opus',
            'video/webm;codecs=vp8',
            'video/webm;codecs=vp9,opus',
            'video/webm;codecs=vp9',
            'video/webm',
        ];
        let recorderMime = 'video/webm';
        for (const mt of mimeTypes) {
            if (MediaRecorder.isTypeSupported(mt)) { recorderMime = mt; break; }
        }

        // Create an off-screen video + canvas pipeline
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');

        // Load best clip to get dimensions
        const metaVideo = document.createElement('video');
        metaVideo.muted = true;
        metaVideo.playsInline = true;
        metaVideo.src = URL.createObjectURL(metaBlob);
        await new Promise((resolve, reject) => {
            metaVideo.onloadedmetadata = resolve;
            metaVideo.onerror = () => reject(new Error('Cannot read video metadata from any clip'));
            setTimeout(() => reject(new Error('Metadata timeout')), 10000);
        });
        canvas.width = metaVideo.videoWidth || 640;
        canvas.height = metaVideo.videoHeight || 480;
        URL.revokeObjectURL(metaVideo.src);

        // Set up MediaRecorder on canvas stream
        const canvasStream = canvas.captureStream(30);
        const recorder = new MediaRecorder(canvasStream, {
            mimeType: recorderMime,
            videoBitsPerSecond: 2500000
        });
        const chunks = [];
        recorder.ondataavailable = e => { if (e.data.size > 0) chunks.push(e.data); };
        recorder.start(500);

        // Play each clip sequentially (in chronological order)
        let clipsEncoded = 0;
        for (let i = 0; i < buffers.length; i++) {
            setProgress(65 + Math.round((i / buffers.length) * 30));
            showStatus(`Encoding clip ${i + 1} of ${buffers.length}...`);

            const blob = new Blob([buffers[i]], { type: 'video/webm' });
            const url = URL.createObjectURL(blob);
            const vid = document.createElement('video');
            vid.muted = true;
            vid.playsInline = true;
            vid.src = url;

            try {
                // Wait for video to be ready
                const loaded = await new Promise((resolve) => {
                    vid.onloadeddata = () => resolve(true);
                    vid.onerror = () => resolve(false);
                    setTimeout(() => resolve(false), 5000);
                });

                if (!loaded) {
                    console.warn(`Clip ${i+1} could not be loaded, skipping`);
                    URL.revokeObjectURL(url);
                    continue;
                }

                await vid.play().catch(() => {});

                // Draw frames until ended
                await new Promise(resolve => {
                    function drawFrame() {
                        if (!vid.paused && !vid.ended) {
                            ctx.drawImage(vid, 0, 0, canvas.width, canvas.height);
                            requestAnimationFrame(drawFrame);
                        } else {
                            resolve();
                        }
                    }
                    vid.onended = resolve;
                    vid.onerror = resolve;
                    setTimeout(resolve, 30000); // safety timeout per clip
                    drawFrame();
                });
                clipsEncoded++;
            } catch (e) {
                console.warn(`Clip ${i+1} skipped:`, e);
            }

            vid.src = '';
            URL.revokeObjectURL(url);
        }

        if (clipsEncoded === 0) {
            recorder.stop();
            showStatus('❌ No clips could be encoded. Try "Download All (ZIP)" instead.');
            return;
        }

        // Stop recording and download
        recorder.stop();
        await new Promise(resolve => { recorder.onstop = resolve; });

        setProgress(100);
        showStatus('Preparing download...');

        const combined = new Blob(chunks, { type: 'video/webm' });
        const dlUrl = URL.createObjectURL(combined);
        const a = document.createElement('a');
        a.href = dlUrl;
        a.download = `session_${sessionId}_combined.webm`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
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
