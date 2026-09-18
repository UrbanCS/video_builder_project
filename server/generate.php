<?php
declare(strict_types=1);

require __DIR__ . '/upload_sessions.php';

try {
    ensureSession();
    $currentUser = currentUser();
    if ($currentUser === null) {
        jsonResponse(['error' => 'Authentication required'], 401);
    }

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

    $batchDir = '';
    $batchHandle = null;
    $batchState = null;
    $batchToken = (string) ($_POST['upload_token'] ?? '');
    if ($batchToken !== '') {
        requireUploadHeader();
        [$batchDir, $batchHandle, $batchState] = openUploadSession($batchToken, (string) $currentUser['id']);
        $existingPath = getJobPath((string) $batchState['project_id']);
        // Retrying finalization after a lost response must not enqueue twice.
        if (is_file($existingPath)) {
            $existing = readJob((string) $batchState['project_id']);
            if (($existing['user_id'] ?? '') !== (string) $currentUser['id']) {
                jsonResponse(['error' => 'Not authorized'], 403);
            }
            jsonResponse(['ok' => true, 'job_id' => $existing['project_id'], 'status' => $existing['status']]);
        }
        $filesById = [];
        foreach ($batchState['files'] as $id => $file) {
            $filesById[$id] = ['name' => $file['name'], 'size' => $file['size'],
                'tmp_name' => $batchDir . '/' . basename($file['stored']), 'error' => UPLOAD_ERR_OK];
        }
    } else {
        // Backward compatibility for pages left open before the deployment.
        $filesById = normalizeUploadedFiles('files');
    }
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

    $transition = sanitizeTransition((string) ($_POST['transition'] ?? 'cut'));
    $mediaAnimation = sanitizeMediaAnimation((string) ($_POST['media_animation'] ?? 'none'));
    $titleAnimation = sanitizeTitleAnimation((string) ($_POST['title_animation'] ?? 'fade'));
    $backgroundFile = '';
    $music = sanitizeMusicFilename((string) ($_POST['music'] ?? ''));
    $musicMode = 'loop';
    if ($music !== '') {
        $musicPath = realpath(MUSIC_DIR . '/' . $music);
        $musicRoot = realpath(MUSIC_DIR);
        if ($musicPath === false || $musicRoot === false || strncmp($musicPath, $musicRoot, strlen($musicRoot)) !== 0) {
            jsonResponse(['error' => 'Invalid music selection'], 400);
        }
    }

    $homageFrom = sanitizeTitleText((string) ($_POST['homage_from'] ?? ''), 120);
    $clientFirstName = sanitizeTitleText((string) ($_POST['client_first_name'] ?? ''), 80);
    $clientLastName = sanitizeTitleText((string) ($_POST['client_last_name'] ?? ''), 80);
    $tributeName = sanitizeTitleText((string) ($_POST['tribute_name'] ?? ''), 120);
    $introTitle = sanitizeTitleText((string) ($_POST['intro_title'] ?? ''), 120);
    $outroTitle = sanitizeTitleText((string) ($_POST['outro_title'] ?? ''), 120);
    $titleDuration = (int) ($_POST['title_duration'] ?? 4);
    if ($titleDuration < 2 || $titleDuration > 10) {
        jsonResponse(['error' => 'Invalid title duration'], 400);
    }

    $uploadKeys = array_keys($filesById);
    sort($uploadKeys);
    $orderKeys = array_map(static fn($v): string => (string) $v, $order);

    if (count($orderKeys) !== count($uploadKeys)) {
        $expectedFiles = count($orderKeys);
        $receivedFiles = count($uploadKeys);
        $phpFileLimit = (int) ini_get('max_file_uploads');
        if ($expectedFiles > $receivedFiles && $phpFileLimit > 0 && $receivedFiles >= $phpFileLimit) {
            jsonResponse(['error' => sprintf(
                'Le serveur a reçu seulement %d fichiers sur %d. Rechargez la page pour utiliser le nouvel envoi fichier par fichier. Conservez vos fichiers originaux pour les sélectionner à nouveau si nécessaire.',
                $receivedFiles,
                $expectedFiles
            )], 400);
        }
        jsonResponse(['error' => sprintf(
            'Envoi incomplet : %d fichiers reçus sur %d attendus. Votre sélection est conservée; veuillez réessayer.',
            $receivedFiles,
            $expectedFiles
        )], 400);
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

    if (array_sum(array_column($filesById, 'size')) > MAX_TOTAL_UPLOAD_SIZE) {
        jsonResponse(['error' => 'Taille totale trop élevée (1 Go maximum par montage).'], 413);
    }

    $projectId = $batchState !== null ? (string) $batchState['project_id'] : generateId(8);
    $projectDir = UPLOADS_DIR . '/' . $projectId;
    ensureDir($projectDir);
    $logoStoredName = '';

    if (!isOwner($currentUser)) {
        $profile = currentUserProfile($currentUser);
        // Clients can customize the dedication for this montage. Older callers
        // that omit the field still inherit the value from their profile.
        if (!array_key_exists('homage_from', $_POST)) {
            $homageFrom = resolveHomageFrom($profile);
        }
        $clientFirstName = sanitizeTitleText((string) ($profile['client_first_name'] ?? ''), 80);
        $clientLastName = sanitizeTitleText((string) ($profile['client_last_name'] ?? ''), 80);
        $tributeName = sanitizeTitleText((string) ($profile['tribute_name'] ?? ''), 120);

        if (isset($_FILES['logo']) && (int) ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            jsonResponse(['error' => 'Logo réservé au compte administrateur'], 403);
        }
    }

    $photoCount = count(array_filter($filesById, static fn(array $f): bool =>
        detectMediaType(strtolower((string) pathinfo($f['name'], PATHINFO_EXTENSION))) === 'image'));
    $knownDuration = $photoCount * $imageDuration
        + (($introTitle !== '' || $tributeName !== '') ? $titleDuration : 0)
        + ($outroTitle !== '' ? $titleDuration + 2 : 0);
    if ($knownDuration > MAX_TOTAL_DURATION) {
        jsonResponse(['error' => 'Le montage dépasse 10 minutes. Réduisez la durée des photos ou leur nombre.'], 400);
    }

    if (isset($_FILES['logo']) && is_array($_FILES['logo']) && (int) ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $logoError = (int) ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($logoError !== UPLOAD_ERR_OK) {
            jsonResponse(['error' => 'Logo upload failed'], 400);
        }

        $logoName = (string) ($_FILES['logo']['name'] ?? '');
        $logoSize = (int) ($_FILES['logo']['size'] ?? 0);
        $logoTmp = (string) ($_FILES['logo']['tmp_name'] ?? '');
        $logoExt = strtolower((string) pathinfo($logoName, PATHINFO_EXTENSION));
        if (!in_array($logoExt, ['png', 'jpg', 'jpeg'], true)) {
            jsonResponse(['error' => 'Logo format not allowed (PNG/JPG)'], 400);
        }
        if ($logoSize <= 0 || $logoSize > MAX_LOGO_SIZE) {
            jsonResponse(['error' => 'Logo too large (max 5 MB)'], 400);
        }
        if (@getimagesize($logoTmp) === false) {
            jsonResponse(['error' => 'Invalid logo image'], 400);
        }

        $logoStoredName = 'logo_' . generateId(8) . '.' . $logoExt;
        if (!move_uploaded_file($logoTmp, $projectDir . '/' . $logoStoredName)) {
            throw new RuntimeException('Failed to save logo file');
        }
    }

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

        $saved = $batchDir !== ''
            ? copy((string) $file['tmp_name'], $targetPath)
            : move_uploaded_file((string) $file['tmp_name'], $targetPath);
        if (!$saved) {
            throw new RuntimeException('Failed to save uploaded file');
        }

        $type = detectMediaType($extension);
        if ($type === 'image') {
            optimizeStoredImage($targetPath, $extension);
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
        'user_id' => (string) ($currentUser['id'] ?? ''),
        'user_email' => (string) ($currentUser['email'] ?? ''),
        'status' => 'pending',
        'created_at' => gmdate('c'),
        'music' => $music,
        'music_mode' => $musicMode,
        'transition' => $transition,
        'media_animation' => $mediaAnimation,
        'title_animation' => $titleAnimation,
        'background' => $backgroundFile,
        'title_duration' => $titleDuration,
        'intro_title' => $introTitle,
        'outro_title' => $outroTitle,
        'homage_from' => $homageFrom,
        'client_first_name' => $clientFirstName,
        'client_last_name' => $clientLastName,
        'tribute_name' => $tributeName,
        'logo_file' => $logoStoredName,
        'media' => $media,
    ];

    $projectJobPath = $projectDir . '/job.json';
    $projectJobJson = json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($projectJobJson === false || file_put_contents($projectJobPath, $projectJobJson, LOCK_EX) === false) {
        throw new RuntimeException('Failed to write project job.json');
    }

    // Publish the complete JSON atomically so cron cannot read a partial job.
    $queueTemp = JOBS_DIR . '/' . $projectId . '.pending';
    if (file_put_contents($queueTemp, $projectJobJson, LOCK_EX) === false
        || !rename($queueTemp, getJobPath($projectId))) {
        throw new RuntimeException('Failed to publish job');
    }

    if ($batchState !== null) {
        // Keep the small manifest for idempotent retries, but not duplicate media.
        foreach ($batchState['files'] as $file) {
            @unlink($batchDir . '/' . basename($file['stored']));
        }
    }

    jsonResponse([
        'ok' => true,
        'job_id' => $projectId,
        'status' => 'pending',
    ]);
} catch (Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
