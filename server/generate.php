<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'Method not allowed'], 405);
    }

    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMaxBytes = iniSizeToBytes((string) ini_get('post_max_size'));
    if ($postMaxBytes > 0 && $contentLength > $postMaxBytes) {
        jsonResponse([
            'error' => 'Upload too large for server limit (post_max_size).',
            'details' => [
                'content_length' => $contentLength,
                'post_max_size' => (string) ini_get('post_max_size'),
                'post_max_bytes' => $postMaxBytes,
            ],
        ], 413);
    }

    ensureDir(UPLOADS_DIR);
    ensureDir(JOBS_DIR);

    $filesById = normalizeUploadedFiles('files');
    if (count($filesById) === 0) {
        jsonResponse(['error' => 'No files uploaded'], 400);
    }

    if (count($filesById) > MAX_FILES) {
        jsonResponse(['error' => 'Too many files. Max: ' . MAX_FILES], 400);
    }

    $orderRaw = $_POST['order_json'] ?? '';
    $order = json_decode((string) $orderRaw, true);
    if (!is_array($order) || count($order) === 0) {
        jsonResponse(['error' => 'Invalid order_json'], 400);
    }

    $imageDuration = (int) ($_POST['image_duration'] ?? DEFAULT_IMAGE_DURATION);
    if ($imageDuration < 1 || $imageDuration > MAX_IMAGE_DURATION) {
        jsonResponse(['error' => 'Invalid image duration'], 400);
    }

    $music = sanitizeMusicFilename((string) ($_POST['music'] ?? ''));
    $musicMode = sanitizeMusicMode((string) ($_POST['music_mode'] ?? 'loop'));
    if ($music !== '') {
        $musicPath = realpath(MUSIC_DIR . '/' . $music);
        $musicRoot = realpath(MUSIC_DIR);
        if ($musicPath === false || $musicRoot === false || strncmp($musicPath, $musicRoot, strlen($musicRoot)) !== 0) {
            jsonResponse(['error' => 'Invalid music selection'], 400);
        }
    }

    $uploadKeys = array_keys($filesById);
    sort($uploadKeys);
    $orderKeys = array_map(static fn($v): string => (string) $v, $order);

    if (count($orderKeys) !== count($uploadKeys)) {
        jsonResponse(['error' => 'Order count does not match uploaded files'], 400);
    }

    $uniqueOrderKeys = array_unique($orderKeys);
    if (count($uniqueOrderKeys) !== count($orderKeys)) {
        jsonResponse(['error' => 'Duplicate IDs in order_json'], 400);
    }

    foreach ($orderKeys as $key) {
        if (!array_key_exists($key, $filesById)) {
            jsonResponse(['error' => 'Unknown file ID in order_json: ' . $key], 400);
        }
    }

    $projectId = generateId(8);
    $projectDir = UPLOADS_DIR . '/' . $projectId;
    ensureDir($projectDir);

    $media = [];
    $imageCount = 0;

    foreach ($orderKeys as $id) {
        $file = $filesById[$id];
        $originalName = (string) $file['name'];
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($extension, ALLOWED_EXTENSIONS, true)) {
            throw new RuntimeException('Unsupported extension: ' . $originalName);
        }

        validateUploadedFile($file, $extension);

        $storedName = generateId(16) . '.' . $extension;
        $targetPath = $projectDir . '/' . $storedName;

        if (!move_uploaded_file((string) $file['tmp_name'], $targetPath)) {
            throw new RuntimeException('Failed to save uploaded file');
        }

        $type = detectMediaType($extension);
        if ($type === 'image') {
            $imageCount++;
            $media[] = [
                'type' => 'image',
                'file' => $storedName,
                'duration' => $imageDuration,
                'original_name' => $originalName,
            ];
        } else {
            $media[] = [
                'type' => 'video',
                'file' => $storedName,
                'original_name' => $originalName,
            ];
        }
    }

    if (($imageCount * $imageDuration) > MAX_TOTAL_DURATION) {
        throw new RuntimeException('Image duration exceeds max total duration of ' . MAX_TOTAL_DURATION . ' seconds');
    }

    $job = [
        'project_id' => $projectId,
        'status' => 'pending',
        'created_at' => gmdate('c'),
        'music' => $music,
        'music_mode' => $musicMode,
        'media' => $media,
    ];

    $projectJobPath = $projectDir . '/job.json';
    $projectJobJson = json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($projectJobJson === false || file_put_contents($projectJobPath, $projectJobJson, LOCK_EX) === false) {
        throw new RuntimeException('Failed to write project job.json');
    }

    writeJob($job);

    jsonResponse([
        'ok' => true,
        'job_id' => $projectId,
        'status' => 'pending',
    ]);
} catch (Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
