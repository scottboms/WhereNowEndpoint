<?php
declare(strict_types=1);

const TEST_TOKEN = 'test-release-token';

$root = dirname(__DIR__);
$endpointDir = $root . '/wherenow';
$testsRun = 0;
$testsFailed = 0;
$serverProcess = null;
$tempDir = null;
$baseUrl = null;
$logFile = null;

function fail(string $message): void {
	global $testsFailed;
	$testsFailed++;
	echo "FAIL {$message}\n";
}

function pass(string $message): void {
	echo "PASS {$message}\n";
}

function test(string $name, callable $fn): void {
	global $testsRun;
	$testsRun++;

	try {
		$fn();
		pass($name);
	} catch (Throwable $e) {
		fail($name . ': ' . $e->getMessage());
	}
}

function assertTrue(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function assertSame(mixed $expected, mixed $actual, string $message): void {
	if ($expected !== $actual) {
		throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
	}
}

function assertArrayHasKeyValue(array $array, string $key, mixed $expected, string $message): void {
	assertTrue(array_key_exists($key, $array), $message . " Missing key {$key}");
	assertSame($expected, $array[$key], $message);
}

function makeTempDir(): string {
	$path = sys_get_temp_dir() . '/wherenow-tests-' . bin2hex(random_bytes(8));
	if (!mkdir($path, 0700, true)) {
		throw new RuntimeException("Unable to create temp dir: {$path}");
	}

	return $path;
}

function removeTree(string $path): void {
	if (!is_dir($path)) {
		return;
	}

	$items = scandir($path);
	if ($items === false) {
		return;
	}

	foreach ($items as $item) {
		if ($item === '.' || $item === '..') {
			continue;
		}

		$itemPath = $path . DIRECTORY_SEPARATOR . $item;
		if (is_dir($itemPath) && !is_link($itemPath)) {
			removeTree($itemPath);
			@rmdir($itemPath);
		} else {
			@unlink($itemPath);
		}
	}

	@rmdir($path);
}

function findFreePort(): int {
	$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
	if ($socket === false) {
		throw new RuntimeException("Unable to allocate test port: {$errstr}");
	}

	$name = stream_socket_get_name($socket, false);
	fclose($socket);
	if (!is_string($name) || !str_contains($name, ':')) {
		throw new RuntimeException('Unable to determine test port');
	}

	return (int)substr(strrchr($name, ':'), 1);
}

function startServer(string $serverDir): array {
	$port = findFreePort();
	$command = [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $serverDir];
	$descriptors = [
		0 => ['pipe', 'r'],
		1 => ['pipe', 'w'],
		2 => ['pipe', 'w'],
	];
	$process = proc_open($command, $descriptors, $pipes);
	if (!is_resource($process)) {
		throw new RuntimeException('Unable to start PHP test server');
	}

	foreach ($pipes as $pipe) {
		stream_set_blocking($pipe, false);
	}

	$url = "http://127.0.0.1:{$port}";
	$deadline = microtime(true) + 5;
	while (microtime(true) < $deadline) {
		$response = httpRequest('GET', $url . '/?ping=1');
		if ($response['status'] === 200) {
			return [$process, $pipes, $url];
		}
		usleep(100000);
	}

	proc_terminate($process);
	throw new RuntimeException('PHP test server did not become ready');
}

function stopServer(mixed $process, array $pipes): void {
	foreach ($pipes as $pipe) {
		if (is_resource($pipe)) {
			fclose($pipe);
		}
	}

	if (is_resource($process)) {
		proc_terminate($process);
		proc_close($process);
	}
}

function httpRequest(string $method, string $url, ?array $body = null, ?string $token = TEST_TOKEN): array {
	$headers = ['Content-Type: application/json'];
	if ($token !== null) {
		$headers[] = 'Authorization: Bearer ' . $token;
	}

	$options = [
		'http' => [
			'method' => $method,
			'header' => implode("\r\n", $headers),
			'ignore_errors' => true,
			'timeout' => 5,
		],
	];

	if ($body !== null) {
		$options['http']['content'] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	$responseHeaders = [];
	$bodyText = @file_get_contents($url, false, stream_context_create($options));
	if (function_exists('http_get_last_response_headers')) {
		$lastHeaders = http_get_last_response_headers();
		if (is_array($lastHeaders)) {
			$responseHeaders = $lastHeaders;
		}
	} else {
		$headerVariable = 'http_response_' . 'header';
		if (isset($$headerVariable) && is_array($$headerVariable)) {
			$responseHeaders = $$headerVariable;
		}
	}

	$status = 0;
	foreach ($responseHeaders as $header) {
		if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
			$status = (int)$matches[1];
			break;
		}
	}

	$decoded = json_decode((string)$bodyText, true);

	return [
		'status' => $status,
		'body' => $decoded,
		'raw' => (string)$bodyText,
		'headers' => $responseHeaders,
	];
}

function writeLog(array $rows): void {
	global $logFile;
	$lines = [];
	foreach ($rows as $row) {
		$lines[] = is_string($row) ? $row : json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	file_put_contents($logFile, implode("\n", $lines) . (count($lines) > 0 ? "\n" : ''));
}

function readLog(): array {
	global $logFile;
	$contents = trim((string)file_get_contents($logFile));
	if ($contents === '') {
		return [];
	}

	return array_map(
		static fn(string $line): mixed => json_decode($line, true),
		explode("\n", $contents)
	);
}

function locationPayload(array $overrides = []): array {
	return array_merge([
		'id' => '8f84f6af-f7e1-4db7-9c93-16d89f2a45db',
		'lat' => 33.812078,
		'lon' => -117.918963,
		'timestamp' => '2026-01-01T12:00:00Z',
		'accuracy' => 12,
		'label' => 'Disneyland',
		'note' => 'Main gate',
		'category' => 'Entertainment and Recreation',
		'reason' => 'upload',
	], $overrides);
}

try {
	$tempDir = makeTempDir();
	$serverDir = $tempDir . '/wherenow';
	$logFile = $tempDir . '/geo.log.jsonl';

	if (!mkdir($serverDir, 0700, true)) {
		throw new RuntimeException('Unable to create test server dir');
	}
	if (!copy($endpointDir . '/index.php', $serverDir . '/index.php')) {
		throw new RuntimeException('Unable to copy endpoint for tests');
	}

	$config = "<?php\nconst TOKEN = '" . TEST_TOKEN . "';\nconst LOG_FILE = '" . addslashes($logFile) . "';\n";
	file_put_contents($serverDir . '/config.php', $config);
	file_put_contents($logFile, '');

	[$serverProcess, $serverPipes, $baseUrl] = startServer($serverDir);

	// Confirms the unauthenticated health check stays publicly reachable.
	test('public ping succeeds without auth', function () use ($baseUrl): void {
		$response = httpRequest('GET', $baseUrl . '/?ping=1', null, null);
		assertSame(200, $response['status'], 'Unexpected status');
		assertArrayHasKeyValue($response['body'], 'ok', true, 'Unexpected body');
	});

	// Confirms the authenticated health check does not allow anonymous access.
	test('authenticated ping rejects missing token', function () use ($baseUrl): void {
		$response = httpRequest('GET', $baseUrl . '/?ping=auth', null, null);
		assertSame(401, $response['status'], 'Unexpected status');
		assertArrayHasKeyValue($response['body'], 'error', 'unauthorized', 'Unexpected body');
	});

	// Confirms a valid bearer token is accepted by the authenticated health check.
	test('authenticated ping accepts valid token', function () use ($baseUrl): void {
		$response = httpRequest('GET', $baseUrl . '/?ping=auth');
		assertSame(200, $response['status'], 'Unexpected status');
		assertArrayHasKeyValue($response['body'], 'ok', true, 'Unexpected body');
	});

	// Confirms writes cannot happen without a bearer token.
	test('mutating routes reject missing token', function () use ($baseUrl): void {
		$response = httpRequest('POST', $baseUrl . '/', locationPayload(), null);
		assertSame(401, $response['status'], 'Unexpected status');
		assertArrayHasKeyValue($response['body'], 'error', 'unauthorized', 'Unexpected body');
	});

	// Confirms a valid upload is appended, normalized, and trimmed before storage.
	test('post stores a valid location row', function () use ($baseUrl): void {
		writeLog([]);
		$response = httpRequest('POST', $baseUrl . '/', locationPayload(['label' => '  Main Gate  ']));
		assertSame(200, $response['status'], 'Unexpected status');
		assertArrayHasKeyValue($response['body'], 'ok', true, 'Unexpected body');

		$rows = readLog();
		assertSame(1, count($rows), 'Expected one log row');
		assertSame('main gate', strtolower($rows[0]['label']), 'Expected trimmed label');
		assertSame('8f84f6af-f7e1-4db7-9c93-16d89f2a45db', $rows[0]['id'], 'Expected normalized id');
	});

	// Confirms latitude validation rejects values outside the valid coordinate range.
	test('post rejects invalid latitude', function () use ($baseUrl): void {
		$response = httpRequest('POST', $baseUrl . '/', locationPayload(['lat' => 91]));
		assertSame(400, $response['status'], 'Unexpected status');
		assertArrayHasKeyValue($response['body'], 'error', 'bad_lat', 'Unexpected body');
	});

	// Confirms uploads must include a valid UUID-shaped id.
	test('post rejects invalid uuid', function () use ($baseUrl): void {
		$response = httpRequest('POST', $baseUrl . '/', locationPayload(['id' => 'not-a-uuid']));
		assertSame(400, $response['status'], 'Unexpected status');
		assertArrayHasKeyValue($response['body'], 'error', 'bad_id', 'Unexpected body');
	});

	// Confirms label length limits are enforced before writing to the log.
	test('post rejects overlong label', function () use ($baseUrl): void {
		$response = httpRequest('POST', $baseUrl . '/', locationPayload(['label' => str_repeat('a', 61)]));
		assertSame(400, $response['status'], 'Unexpected status');
		assertArrayHasKeyValue($response['body'], 'error', 'bad_label', 'Unexpected body');
	});

	// Confirms reading stored locations requires authentication.
	test('get rejects missing token', function () use ($baseUrl): void {
		$response = httpRequest('GET', $baseUrl . '/', null, null);
		assertSame(401, $response['status'], 'Unexpected status');
		assertArrayHasKeyValue($response['body'], 'error', 'unauthorized', 'Unexpected body');
	});

	// Confirms reads return upload rows newest-first while filtering other reasons.
	test('get returns newest upload rows and skips non-upload rows', function () use ($baseUrl): void {
		writeLog([
			locationPayload(['id' => '11111111-1111-4111-8111-111111111111', 'label' => 'old']),
			locationPayload(['id' => '22222222-2222-4222-8222-222222222222', 'label' => 'hidden', 'reason' => 'manual']),
			locationPayload(['id' => '33333333-3333-4333-8333-333333333333', 'label' => 'new']),
		]);

		$response = httpRequest('GET', $baseUrl . '/?limit=10');
		assertSame(200, $response['status'], 'Unexpected status');
		assertSame(2, count($response['body']), 'Expected two upload rows');
		assertSame('new', $response['body'][0]['label'], 'Expected newest row first');
		assertSame('old', $response['body'][1]['label'], 'Expected older row second');
	});

	// Confirms an empty upload result reports the expected no-location response.
	test('get reports no location when log has no upload rows', function () use ($baseUrl): void {
		writeLog([locationPayload(['reason' => 'manual'])]);
		$response = httpRequest('GET', $baseUrl . '/');
		assertSame(200, $response['status'], 'Unexpected status');
		assertArrayHasKeyValue($response['body'], 'error', 'no_location_found', 'Unexpected body');
	});

	// Confirms PATCH rewrites the matching row and records an update timestamp.
	test('patch updates an existing row', function () use ($baseUrl): void {
		writeLog([locationPayload()]);
		$response = httpRequest('PATCH', $baseUrl . '/', [
			'id' => '8f84f6af-f7e1-4db7-9c93-16d89f2a45db',
			'label' => '  Updated  ',
			'lat' => 34.1,
			'lon' => -118.2,
		]);

		assertSame(200, $response['status'], 'Unexpected status');
		assertArrayHasKeyValue($response['body'], 'ok', true, 'Unexpected body');
		$rows = readLog();
		assertSame('Updated', $rows[0]['label'], 'Expected patched label');
		assertSame(34.1, $rows[0]['lat'], 'Expected patched lat');
		assertTrue(isset($rows[0]['updatedAt']), 'Expected updatedAt');
	});

	// Confirms PATCH with only an id is treated as a successful no-op.
	test('patch returns noop when no patchable fields are supplied', function () use ($baseUrl): void {
		$response = httpRequest('PATCH', $baseUrl . '/', ['id' => '8f84f6af-f7e1-4db7-9c93-16d89f2a45db']);
		assertSame(200, $response['status'], 'Unexpected status');
		assertArrayHasKeyValue($response['body'], 'noop', true, 'Unexpected body');
	});

	// Confirms PATCH fails clearly when the target id is not present in the log.
	test('patch rejects unknown id', function () use ($baseUrl): void {
		writeLog([locationPayload()]);
		$response = httpRequest('PATCH', $baseUrl . '/', [
			'id' => '99999999-9999-4999-8999-999999999999',
			'label' => 'Missing',
		]);
		assertSame(404, $response['status'], 'Unexpected status');
		assertArrayHasKeyValue($response['body'], 'error', 'id_not_found', 'Unexpected body');
	});

	// Confirms DELETE removes the matching row without removing unrelated entries.
	test('delete removes an existing row', function () use ($baseUrl): void {
		writeLog([
			locationPayload(),
			locationPayload(['id' => '33333333-3333-4333-8333-333333333333', 'label' => 'kept']),
		]);

		$response = httpRequest('DELETE', $baseUrl . '/', ['id' => '8f84f6af-f7e1-4db7-9c93-16d89f2a45db']);
		assertSame(200, $response['status'], 'Unexpected status');
		assertArrayHasKeyValue($response['body'], 'deleted', true, 'Unexpected body');

		$rows = readLog();
		assertSame(1, count($rows), 'Expected one remaining row');
		assertSame('kept', $rows[0]['label'], 'Expected second row to remain');
	});

	// Confirms DELETE fails clearly when the target id is not present in the log.
	test('delete rejects unknown id', function () use ($baseUrl): void {
		writeLog([locationPayload()]);
		$response = httpRequest('DELETE', $baseUrl . '/', ['id' => '99999999-9999-4999-8999-999999999999']);
		assertSame(404, $response['status'], 'Unexpected status');
		assertArrayHasKeyValue($response['body'], 'error', 'id_not_found', 'Unexpected body');
	});
} finally {
	if (isset($serverProcess, $serverPipes)) {
		stopServer($serverProcess, $serverPipes);
	}
	if (is_string($tempDir)) {
		removeTree($tempDir);
	}
}

echo "\n{$testsRun} tests, {$testsFailed} failures\n";
exit($testsFailed > 0 ? 1 : 0);
