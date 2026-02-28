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

// if not POST, PATCH, and not GET
if($method !== 'POST' && $method !== 'PATCH' && $method !== 'GET') {
	http_response_code(405);
	echo json_encode(['error' => 'method_not_allowed']);
	exit;
}


// Extract the bearer token from request headers, if present.
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

function isValidUuid(string $value): bool {
	return (bool)preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', $value);
}

// Return string length with UTF-8 support when mbstring is available.
function stringLength(string $value): int {
	if (function_exists('mb_strlen')) {
		return mb_strlen($value, 'UTF-8');
	}

	return strlen($value);
}

// Validate optional text input, trim edges, and enforce max length.
function normalizeTextField(mixed $value, int $maxLen, string $errorCode): ?string {
	if ($value === null) {
		return null;
	}
	if (!is_string($value)) {
		http_response_code(400);
		echo json_encode(['error' => $errorCode]);
		exit;
	}

	$trimmed = trim($value);
	if (stringLength($trimmed) > $maxLen) {
		http_response_code(400);
		echo json_encode(['error' => $errorCode]);
		exit;
	}

	return $trimmed;
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
				'id' => $data['id'] ?? null,
				'lat' => $data['lat'] ?? null,
				'lon' => $data['lon'] ?? null,
				'timestamp' => $data['timestamp'] ?? null,
				'accuracy' => $data['accuracy'] ?? null,
				'label' => $data['label'] ?? null,
				'note' => $data['note'] ?? null,
				'category' => $data['category'] ?? null,
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
						'id' => $data['id'] ?? null,
						'lat' => $data['lat'] ?? null,
						'lon' => $data['lon'] ?? null,
						'timestamp' => $data['timestamp'] ?? null,
						'accuracy' => $data['accuracy'] ?? null,
						'label' => $data['label'] ?? null,
						'note' => $data['note'] ?? null,
						'category' => $data['category'] ?? null,
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

	echo json_encode($entries);
	exit;
}


/*
 * ----------------------------------------------------------------------------
 * POST/PATCH: SAVE OR PATCH LOCATIONS IN LOG ======
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

if ($method === 'PATCH') {
	$id = $data['id'] ?? null;
	if (!is_string($id) || !isValidUuid($id)) {
		http_response_code(400);
		echo json_encode(['error' => 'bad_id']);
		exit;
	}
	$id = strtolower($id);

	$hasLabel = array_key_exists('label', $data);
	$hasNote = array_key_exists('note', $data);
	$hasCategory = array_key_exists('category', $data);
	if (!$hasLabel && !$hasNote && !$hasCategory) {
		echo json_encode([
			'ok' => true,
			'id' => $id,
			'noop' => true,
		]);
		exit;
	}

	$label = $hasLabel ? normalizeTextField($data['label'], 60, 'bad_label') : null;
	$note = $hasNote ? normalizeTextField($data['note'], 500, 'bad_note') : null;
	$category = $hasCategory ? normalizeTextField($data['category'], 60, 'bad_category') : null;

	$fp = fopen(LOG_FILE, 'c+b');
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

	$tempPath = tempnam(sys_get_temp_dir(), 'geo_patch_');
	if ($tempPath === false) {
		flock($fp, LOCK_UN);
		fclose($fp);
		http_response_code(500);
		echo json_encode(['error' => 'cannot_create_temp']);
		exit;
	}

	$tmp = fopen($tempPath, 'wb');
	if ($tmp === false) {
		@unlink($tempPath);
		flock($fp, LOCK_UN);
		fclose($fp);
		http_response_code(500);
		echo json_encode(['error' => 'cannot_open_temp']);
		exit;
	}

	rewind($fp);
	$updated = false;

	while (($line = fgets($fp)) !== false) {
		$trimmedLine = trim($line);
		if ($trimmedLine !== '') {
			$entry = json_decode($trimmedLine, true);
			if (
				!$updated &&
				is_array($entry) &&
				is_string($entry['id'] ?? null) &&
				strtolower($entry['id']) === $id
			) {
				if ($hasLabel) {
					$entry['label'] = $label;
				}
				if ($hasNote) {
					$entry['note'] = $note;
				}
				if ($hasCategory) {
					$entry['category'] = $category;
				}
				$entry['updatedAt'] = gmdate('c');
				$encoded = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
				if ($encoded === false) {
					fclose($tmp);
					@unlink($tempPath);
					flock($fp, LOCK_UN);
					fclose($fp);
					http_response_code(500);
					echo json_encode(['error' => 'encode_failed']);
					exit;
				}
				$line = $encoded . "\n";
				$updated = true;
			}
		}

		if (fwrite($tmp, $line) === false) {
			fclose($tmp);
			@unlink($tempPath);
			flock($fp, LOCK_UN);
			fclose($fp);
			http_response_code(500);
			echo json_encode(['error' => 'write_failed']);
			exit;
		}
	}

	if (!feof($fp)) {
		fclose($tmp);
		@unlink($tempPath);
		flock($fp, LOCK_UN);
		fclose($fp);
		http_response_code(500);
		echo json_encode(['error' => 'read_failed']);
		exit;
	}

	fflush($tmp);
	fclose($tmp);

	if (!$updated) {
		@unlink($tempPath);
		flock($fp, LOCK_UN);
		fclose($fp);
		http_response_code(404);
		echo json_encode(['error' => 'id_not_found']);
		exit;
	}

	$tmpRead = fopen($tempPath, 'rb');
	if ($tmpRead === false) {
		@unlink($tempPath);
		flock($fp, LOCK_UN);
		fclose($fp);
		http_response_code(500);
		echo json_encode(['error' => 'cannot_open_temp']);
		exit;
	}

	if (!ftruncate($fp, 0)) {
		fclose($tmpRead);
		@unlink($tempPath);
		flock($fp, LOCK_UN);
		fclose($fp);
		http_response_code(500);
		echo json_encode(['error' => 'write_failed']);
		exit;
	}
	rewind($fp);

	$copied = stream_copy_to_stream($tmpRead, $fp);
	fclose($tmpRead);
	@unlink($tempPath);

	if ($copied === false) {
		flock($fp, LOCK_UN);
		fclose($fp);
		http_response_code(500);
		echo json_encode(['error' => 'write_failed']);
		exit;
	}

	fflush($fp);
	flock($fp, LOCK_UN);
	fclose($fp);

	echo json_encode([
		'ok' => true,
		'id' => $id,
		'label' => $hasLabel ? $label : null,
		'note' => $hasNote ? $note : null,
		'category' => $hasCategory ? $category : null,
	]);
	exit;
}

// VALIDATE FIELDS
$lat = $data['lat'] ?? null;
$lon = $data['lon'] ?? null;
$id = $data['id'] ?? null;
$hasTimestamp = array_key_exists('timestamp', $data);
$timestamp = $hasTimestamp ? $data['timestamp'] : null;
$accuracy = $data['accuracy'] ?? null;
$label = normalizeTextField($data['label'] ?? null, 60, 'bad_label');
$note = normalizeTextField($data['note'] ?? null, 500, 'bad_note');
$category = normalizeTextField($data['category'] ?? null, 60, 'bad_category');
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
if (!is_string($id) || !isValidUuid($id)) {
	http_response_code(400);
	echo json_encode(['error' => 'bad_id']);
	exit;
}
if ($hasTimestamp && !is_string($timestamp)) {
	http_response_code(400);
	echo json_encode(['error' => 'bad_timestamp']);
	exit;
}

// build record
$record = [
	'id' => strtolower($id),
	'lat' => (float)$lat,
	'lon' => (float)$lon,
	// Preserve client-provided timestamp string exactly; only default when absent.
	'timestamp' => $hasTimestamp ? $timestamp : gmdate('c'),
	'accuracy' => is_numeric($accuracy) ? (float)$accuracy : null,
	'label' => $label,
	'note' => $note,
	'category' => $category,
	'reason' => is_string($reason) ? $reason : null,
	'receivedAt' => gmdate('c'),
	'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
];

// JSON Lines line
$encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($encoded === false) {
	http_response_code(500);
	echo json_encode(['error' => 'encode_failed']);
	exit;
}
$line = $encoded . "\n";

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
