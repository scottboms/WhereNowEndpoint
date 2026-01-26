<?php
// index.php

/*
 * If PHP never sees HTTP_AUTHORIZATION, 
 * add this to site config or .htaccess
 * 
 * SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
 */

declare(strict_types=1);

/* 
 * ----------------------------------------------------------------------------
 * CONFIG
 * ----------------------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
const MAX_BODY_BYTES = 65536; // 64KB

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// if not POST and not GET
if($method !== 'POST' && $method !== 'GET') {
	http_response_code(405);
	echo json_encode(['error' => 'method_not_allowed']);
	exit;
}


// Get Bearer Token
function getBearerToken(): ?string {
	$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

	if (!$auth && function_exists('apache_request_headers')) {
		$headers = apache_request_headers();
		$auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
	}

	if (!preg_match('/^Bearer\s+(.+)$/i', trim($auth), $m)) {
		return null;
	}

	return $m[1];
}

/*
 * ----------------------------------------------------------------------------
 * TEST PING to API endpoint
 * Use: /api/?ping=1
 * Does not require BEARER TOKEN
 * ----------------------------------------------------------------------------
 */

if ($method === 'GET' && (($_GET['ping'] ?? null) === '1')) {
	http_response_code(200);
  echo json_encode(['ok' => true]);
  exit;
}

/*
 * ----------------------------------------------------------------------------
 * AUTHENTICATED PING
 * Use: /api/?ping=auth
 * Requires valid BEARER TOKEN
 * ----------------------------------------------------------------------------
 */
if ($method === 'GET' && ($_GET['ping'] ?? null) === 'auth') {
	$token = getBearerToken();

	if (!$token || !hash_equals(TOKEN, $token)) {
		http_response_code(401);
		echo json_encode(['error' => 'unauthorized']);
		exit;
	}
	
	http_response_code(200);
	echo json_encode(['ok' => true]);
	exit;
}


// AUTH: Bearer token (shared)
$token = getBearerToken();

if (!$token || !hash_equals(TOKEN, $token)) {
	http_response_code(401);
	echo json_encode(['error' => 'unauthorized']);
	exit;
}


/*
 * ----------------------------------------------------------------------------
 * GET: READ LOG ENTRIES
 * ----------------------------------------------------------------------------
 */
if ($method === 'GET') {
	if (!is_readable(LOG_FILE)) {
		http_response_code(500);
		echo json_encode(['error' => 'log_not_readable']);
		exit;
	}

	// optional: ?limit=200 (cap at 200)
	$limit = (int)($_GET['limit'] ?? 200);
	if ($limit <= 0) $limit = 200;
	if ($limit > 200) $limit = 200;

	$entries = [];

	$fh = fopen(LOG_FILE, 'rb');
	if ($fh === false) {
		http_response_code(500);
		echo json_encode(['error' => 'cannot_open_log']);
		exit;
	}

	// read from the end in chunks; collect newest upload entries first
	$chunkSize = 4096;
	$buffer = '';

	fseek($fh, 0, SEEK_END);
	$pos = ftell($fh);

	while ($pos > 0 && count($entries) < $limit) {
		$readSize = ($pos >= $chunkSize) ? $chunkSize : $pos;
		$pos -= $readSize;
		fseek($fh, $pos);

		$chunk = fread($fh, $readSize);
		if ($chunk === false) break;

		$buffer = $chunk . $buffer;
		$lines = explode("\n", $buffer);

		// keep the first (possibly partial) line for the next iteration
		$buffer = array_shift($lines) ?? '';

		// process complete lines from bottom to top
		for ($i = count($lines) - 1; $i >= 0; $i--) {
			if (count($entries) >= $limit) break;

			$line = trim($lines[$i]);
			if ($line === '') continue;

			$data = json_decode($line, true);
			if (!is_array($data)) continue;

			// treat missing reason as "upload" for backwards compatibility
			$reason = $data['reason'] ?? 'upload';

			// only return upload entries
			if ($reason !== 'upload') continue;

			$entries[] = [
				'lat' => $data['lat'] ?? null,
				'lon' => $data['lon'] ?? null,
				'timestamp' => $data['timestamp'] ?? null,
				'accuracy' => $data['accuracy'] ?? null,
				'reason' => $reason,
			];
		}
	}
	
	// also check any remaining buffer as a last resort
	if (count($entries) < $limit) {
		$line = trim($buffer);
		if ($line !== '') {
			$data = json_decode($line, true);
			if (is_array($data)) {
				$reason = $data['reason'] ?? 'upload';
				if ($reason === 'upload') {
					$entries[] = [
						'lat' => $data['lat'] ?? null,
						'lon' => $data['lon'] ?? null,
						'timestamp' => $data['timestamp'] ?? null,
						'accuracy' => $data['accuracy'] ?? null,
						'reason' => $reason,
					];
				}
			}
		}
	}

	fclose($fh);

	if (empty($entries)) {
		echo json_encode(['error' => 'no_location_found']);
		exit;
	}

	// add incremental IDs (newest first)
	foreach ($entries as $i => &$entry) {
		$entry['id'] = $i + 1;
	}
	unset($entry);

	echo json_encode($entries);
	exit;
}


/*
 * ----------------------------------------------------------------------------
 * POST: SAVE LOCATIONS TO LOG ======
 * ----------------------------------------------------------------------------
 */

// basic body size limit (defense in depth)
$len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($len > MAX_BODY_BYTES) {
	http_response_code(413);
	echo json_encode(['error' => 'payload_too_large']);
	exit;
}

// READ JSON BODY
$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);

if (!is_array($data)) {
	http_response_code(400);
	echo json_encode(['error' => 'invalid_json']);
	exit;
}

// VALIDATE FIELDS
$lat = $data['lat'] ?? null;
$lon = $data['lon'] ?? null;
$timestamp = $data['timestamp'] ?? null;
$accuracy = $data['accuracy'] ?? null;
$reason = $data['reason'] ?? null;

if (!is_numeric($lat) || $lat <  -90 || $lat >  90) {
	http_response_code(400);
	echo json_encode(['error' => 'bad_lat']);
	exit;
}
if (!is_numeric($lon) || $lon < -180 || $lon > 180) {
	http_response_code(400);
	echo json_encode(['error' => 'bad_lon']);
	exit;
}

// build record
$record = [
	'lat' => (float)$lat,
	'lon' => (float)$lon,
	'timestamp' => (is_string($timestamp) && $timestamp !== '') ? $timestamp : gmdate('c'),
	'accuracy' => is_numeric($accuracy) ? (float)$accuracy : null,
	'reason' => is_string($reason) ? $reason : null,
	'receivedAt' => gmdate('c'),
	'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
];

// JSON Lines line
$line = json_encode($record, JSON_UNESCAPED_SLASHES) . "\n";

// APPEND WITH LOCK
$fp = fopen(LOG_FILE, 'ab');
if ($fp === false) {
	http_response_code(500);
	echo json_encode(['error' => 'cannot_open_log']);
	exit;
}

if (!flock($fp, LOCK_EX)) {
	fclose($fp);
	http_response_code(500);
	echo json_encode(['error' => 'cannot_lock_log']);
	exit;
}

$ok = fwrite($fp, $line);
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

if ($ok === false) {
	http_response_code(500);
	echo json_encode(['error' => 'write_failed']);
	exit;
}

echo json_encode(['ok' => true]);
