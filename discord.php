<?php
// discord_webhook_proxy.php
// Простой прокси для Discord webhook с поддержкой отправки файлов.
// Настройте URL вебхука: можно положить сюда или в env-переменную DISCORD_WEBHOOK_URL
$DISCORD_WEBHOOK_URL = getenv('DISCORD_WEBHOOK_URL') ?: '';

// max download size for remote files (bytes). Установите в соответствии с лимитами хостинга/Discord.
define('MAX_REMOTE_FILE_BYTES', 8 * 1024 * 1024); // 8 MB

// helper: send JSON response and exit
function send_json($data, $http_code = 200) {
    http_response_code($http_code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// GET — покажем краткую инструкцию
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Discord Webhook Proxy\n\n";
    echo "POST to this endpoint with either multipart/form-data (files + fields) or application/json.\n";
    echo "Accepted JSON fields: content, username, avatar_url, tts (bool), embeds (array), file_urls (array of urls).\n";
    echo "If DISCORD_WEBHOOK_URL is not set in script, set env var DISCORD_WEBHOOK_URL.\n";
    exit;
}

if (empty($DISCORD_WEBHOOK_URL)) {
    send_json(['error' => 'DISCORD_WEBHOOK_URL is not configured on the server'], 500);
}

// Read incoming payload (supports JSON and form-data)
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

$payload = [];
$file_paths_to_cleanup = []; // temp files to delete after send

// If JSON body
if (stripos($contentType, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if ($json === null && $raw !== '') {
        send_json(['error' => 'Invalid JSON body'], 400);
    }
    $payload = is_array($json) ? $json : [];
} else {
    // form-data: take fields from $_POST
    $payload = $_POST;
}

// Build payload_json for Discord (only include known fields)
$discordPayload = [];
if (isset($payload['content'])) $discordPayload['content'] = $payload['content'];
if (isset($payload['username'])) $discordPayload['username'] = $payload['username'];
if (isset($payload['avatar_url'])) $discordPayload['avatar_url'] = $payload['avatar_url'];
if (isset($payload['tts'])) $discordPayload['tts'] = filter_var($payload['tts'], FILTER_VALIDATE_BOOLEAN);
if (isset($payload['embeds'])) {
    // embeds could be JSON string or already array
    if (is_string($payload['embeds'])) {
        $decoded = json_decode($payload['embeds'], true);
        if ($decoded !== null) $discordPayload['embeds'] = $decoded;
    } elseif (is_array($payload['embeds'])) {
        $discordPayload['embeds'] = $payload['embeds'];
    }
}

// Prepare POST fields for curl
$postFields = [];
$postFields['payload_json'] = json_encode($discordPayload, JSON_UNESCAPED_UNICODE);

// 1) Attach files sent in multipart/form-data ($_FILES)
$i = 0;
if (!empty($_FILES)) {
    foreach ($_FILES as $f) {
        // handle both single and multiple inputs
        if (is_array($f['name'])) {
            // multiple files input
            $count = count($f['name']);
            for ($k = 0; $k < $count; $k++) {
                if ($f['error'][$k] !== UPLOAD_ERR_OK) continue;
                $tmp = $f['tmp_name'][$k];
                $name = $f['name'][$k];
                // create CURLFile
                $postFields["file{$i}"] = new CURLFile($tmp, $f['type'][$k] ?? mime_content_type($tmp), $name);
                $i++;
            }
        } else {
            if ($f['error'] === UPLOAD_ERR_OK) {
                $tmp = $f['tmp_name'];
                $name = $f['name'];
                $postFields["file{$i}"] = new CURLFile($tmp, $f['type'] ?? mime_content_type($tmp), $name);
                $i++;
            }
        }
    }
}

// 2) If JSON provided file_urls (array), download them to temp and attach
if (!empty($payload['file_urls']) && is_array($payload['file_urls'])) {
    foreach ($payload['file_urls'] as $url) {
        $url = trim($url);
        if ($url === '') continue;
        // basic validation (allow http/https)
        if (!preg_match('#^https?://#i', $url)) continue;

        // download via curl with size limit
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        // limit download to MAX_REMOTE_FILE_BYTES + 1
        curl_setopt($ch, CURLOPT_BUFFERSIZE, 8192);
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        $tmpfname = tempnam(sys_get_temp_dir(), 'dwp_');
        $fp = fopen($tmpfname, 'w');
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_exec($ch);
        $err = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($fp);

        if ($err) {
            @unlink($tmpfname);
            continue;
        }
        // check size
        $filesize = filesize($tmpfname);
        if ($filesize === false || $filesize > MAX_REMOTE_FILE_BYTES) {
            @unlink($tmpfname);
            continue;
        }
        // guess filename from URL
        $parsed = parse_url($url);
        $basename = basename($parsed['path'] ?? '') ?: 'file_' . $i;
        $mimetype = mime_content_type($tmpfname) ?: 'application/octet-stream';
        $postFields["file{$i}"] = new CURLFile($tmpfname, $mimetype, $basename);
        $file_paths_to_cleanup[] = $tmpfname;
        $i++;
    }
}

// Do the request to Discord
$ch = curl_init($DISCORD_WEBHOOK_URL);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    // Let curl set Content-Type multipart/form-data with boundary
    'User-Agent: Discord-Webhook-Proxy/1.0'
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$response = curl_exec($ch);
$http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err = curl_error($ch);
curl_close($ch);

// cleanup downloaded temp files
foreach ($file_paths_to_cleanup as $p) {
    @unlink($p);
}

// Relay response
if ($response === false) {
    send_json(['error' => 'Failed to contact Discord webhook', 'curl_error' => $curl_err], 500);
}

// Try parse json response from Discord
$decoded = json_decode($response, true);
if ($http_status >= 200 && $http_status < 300) {
    // success — relay discord's response if json, else give raw
    if ($decoded !== null) {
        send_json(['status' => 'ok', 'discord_status' => $http_status, 'discord_response' => $decoded], 200);
    } else {
        send_json(['status' => 'ok', 'discord_status' => $http_status, 'discord_response' => $response], 200);
    }
} else {
    // error — include discord response
    $body = $decoded !== null ? $decoded : $response;
    send_json(['status' => 'error', 'discord_status' => $http_status, 'discord_response' => $body], $http_status ?: 500);
}

