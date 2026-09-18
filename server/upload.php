<?php
declare(strict_types=1);

require __DIR__ . '/upload_sessions.php';

try {
    $user = currentUser();
    if ($user === null) {
        jsonResponse(['error' => 'Authentication required'], 401);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'Method not allowed'], 405);
    }
    requireUploadHeader();
    $postLimit = iniSizeToBytes((string) ini_get('post_max_size'));
    if ($postLimit > 0 && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $postLimit) {
        jsonResponse(['error' => 'Ce fichier dépasse la taille d’envoi autorisée par le serveur.'], 413);
    }
    ensureDir(UPLOADS_DIR);
    $userId = (string) $user['id'];
    if (($_POST['action'] ?? '') === 'start') {
        cleanupExpiredUploadSessions();
        $token = generateId(24);
        $dir = uploadSessionPath($token);
        if (!mkdir($dir, 0700)) {
            throw new RuntimeException('Unable to create upload directory');
        }
        $state = ['user_id' => $userId, 'created_at' => time(), 'project_id' => generateId(8), 'files' => []];
        if (file_put_contents($dir . '/manifest.json', json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new RuntimeException('Unable to initialize upload');
        }
        jsonResponse(['ok' => true, 'upload_token' => $token]);
    }
    [$dir, $handle, $state] = openUploadSession((string) ($_POST['upload_token'] ?? ''), $userId);
    if (is_file(getJobPath((string) $state['project_id']))) {
        jsonResponse(['error' => 'This upload has already been finalized'], 409);
    }
    $id = (string) ($_POST['file_id'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $id)) {
        jsonResponse(['error' => 'Invalid file ID'], 400);
    }
    if (!isset($state['files'][$id]) && count($state['files']) >= MAX_FILES) {
        jsonResponse(['error' => 'Too many files. Max: ' . MAX_FILES], 400);
    }
    $files = normalizeUploadedFiles('files');
    if (count($files) !== 1 || !isset($files[$id])) {
        jsonResponse(['error' => 'Un seul fichier est attendu par envoi.'], 400);
    }
    $file = $files[$id];
    $extension = strtolower((string) pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ALLOWED_EXTENSIONS, true)) {
        jsonResponse(['error' => 'Unsupported file format'], 400);
    }
    validateUploadedFile($file, $extension);
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Invalid uploaded file');
    }
    $digest = hash_file('sha256', $file['tmp_name']);
    if (isset($state['files'][$id])) {
        $previous = $state['files'][$id];
        if ($previous['sha256'] !== $digest || $previous['name'] !== $file['name']) {
            jsonResponse(['error' => 'File ID already used for different content'], 409);
        }
        jsonResponse(['ok' => true, 'file_id' => $id]);
    }
    $totalBytes = array_sum(array_column($state['files'], 'size')) + (int) $file['size'];
    if ($totalBytes > MAX_TOTAL_UPLOAD_SIZE) {
        jsonResponse(['error' => 'Taille totale trop élevée (1 Go maximum par montage).'], 413);
    }
    $stored = generateId(16) . '.' . $extension;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) {
        throw new RuntimeException('Unable to save uploaded file');
    }
    $state['files'][$id] = ['name' => $file['name'], 'size' => $file['size'], 'stored' => $stored, 'sha256' => $digest];
    saveUploadSession($handle, $state);
    jsonResponse(['ok' => true, 'file_id' => $id]);
} catch (Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 400);
}
