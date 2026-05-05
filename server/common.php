<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

const ROOT_DIR = __DIR__ . '/..';
const UPLOADS_DIR = ROOT_DIR . '/uploads';
const OUTPUTS_DIR = ROOT_DIR . '/outputs';
const JOBS_DIR = ROOT_DIR . '/jobs';
const MUSIC_DIR = ROOT_DIR . '/music';
const DATA_DIR = ROOT_DIR . '/data';
const USERS_FILE = DATA_DIR . '/users.json';
const BACKGROUNDS_DIR = ROOT_DIR . '/public/backgrounds';
const BACKGROUNDS_DIR_ALT = ROOT_DIR . '/backgrounds';

const MAX_FILES = 40;
const MAX_TOTAL_DURATION = 600;
const DEFAULT_IMAGE_DURATION = 3;
const MAX_IMAGE_DURATION = 10;
const MAX_IMAGE_SIZE = 30 * 1024 * 1024;   // 30MB
const MAX_VIDEO_SIZE = 150 * 1024 * 1024;  // 150MB
const MAX_LOGO_SIZE = 5 * 1024 * 1024;     // 5MB
const MAX_RENDER_IMAGE_WIDTH = 1920;

const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'mp4'];

function jsonResponse(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function ensureDir(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create directory: ' . $path);
    }
}

function safeJobId(string $jobId): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_-]{6,64}$/', $jobId);
}

function generateId(int $bytes = 8): string
{
    return bin2hex(random_bytes($bytes));
}

function sanitizeMusicFilename(string $filename): string
{
    $base = basename($filename);
    if (!preg_match('/^[A-Za-z0-9._-]+\.mp3$/', $base)) {
        return '';
    }
    return $base;
}

function sanitizeMusicMode(string $mode): string
{
    $normalized = strtolower(trim($mode));
    if (in_array($normalized, ['loop', 'stop'], true)) {
        return $normalized;
    }
    return 'loop';
}

function sanitizeTransition(string $transition): string
{
    $normalized = strtolower(trim($transition));
    if (in_array($normalized, ['cut', 'fade', 'crossfade', 'slide'], true)) {
        return $normalized;
    }
    return 'cut';
}

function sanitizeMediaAnimation(string $animation): string
{
    $normalized = strtolower(trim($animation));
    if (in_array($normalized, ['none', 'zoom_in', 'zoom_out', 'pan_right', 'pan_left', 'rotate', 'slide_premium', 'random'], true)) {
        return $normalized;
    }
    return 'none';
}

function sanitizeTitleAnimation(string $animation): string
{
    $normalized = strtolower(trim($animation));
    if (in_array($normalized, ['none', 'fade', 'slide_up', 'zoom_in'], true)) {
        return $normalized;
    }
    return 'fade';
}

function listPresentationBackgrounds(): array
{
    ensureDir(BACKGROUNDS_DIR);

    $dirs = presentationBackgroundDirs();
    $paths = [];
    foreach ($dirs as $dir) {
        $paths = array_merge(
            $paths,
            glob($dir . '/*.jpg') ?: [],
            glob($dir . '/*.jpeg') ?: [],
            glob($dir . '/*.png') ?: [],
            glob($dir . '/*.webp') ?: []
        );
    }
    $files = [];
    foreach ($paths as $path) {
        $files[] = basename($path);
    }
    $files = array_values(array_unique($files));
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);
    return $files;
}

function presentationBackgroundDirs(): array
{
    $dirs = [];
    if (is_dir(BACKGROUNDS_DIR)) {
        $dirs[] = BACKGROUNDS_DIR;
    }
    if (is_dir(BACKGROUNDS_DIR_ALT)) {
        $dirs[] = BACKGROUNDS_DIR_ALT;
    }
    if ($dirs === []) {
        $dirs[] = BACKGROUNDS_DIR;
    }
    return array_values(array_unique($dirs));
}

function resolvePresentationBackgroundPath(string $filename): string
{
    $safe = sanitizePresentationBackground($filename);
    if ($safe === '') {
        return '';
    }

    foreach (presentationBackgroundDirs() as $dir) {
        $path = $dir . '/' . $safe;
        if (is_file($path)) {
            return $path;
        }
    }

    return '';
}

function presentationBackgroundPublicPrefix(): string
{
    $base = rtrim(BASE_URL, '/') . '/';

    if (is_dir(BACKGROUNDS_DIR_ALT)) {
        return $base . 'backgrounds/';
    }

    return $base . 'public/backgrounds/';
}

function sanitizePresentationBackground(string $filename): string
{
    $base = basename(trim($filename));
    if ($base === '') {
        return '';
    }

    // Prevent traversal/invalid separators while allowing real-world filenames
    // (accents, spaces, parentheses, apostrophes, etc.).
    if (strpos($base, '/') !== false || strpos($base, '\\') !== false || $base === '.' || $base === '..') {
        return '';
    }

    $ext = strtolower((string) pathinfo($base, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        return '';
    }

    return $base;
}

function allowBackgroundMediaAnimation(): bool
{
    return defined('ENABLE_BACKGROUND_MEDIA_ANIMATION') && ENABLE_BACKGROUND_MEDIA_ANIMATION === true;
}

function currentUserProfile(?array $user): array
{
    if (!is_array($user)) {
        return [];
    }
    $profile = $user['profile'] ?? [];
    return is_array($profile) ? $profile : [];
}

function sanitizeTitleText(string $text, int $maxLen = 120): string
{
    $trimmed = trim($text);
    if ($trimmed === '') {
        return '';
    }
    $singleLine = preg_replace('/\s+/', ' ', $trimmed);
    if (!is_string($singleLine)) {
        return '';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($singleLine, 0, $maxLen, 'UTF-8');
    }
    return substr($singleLine, 0, $maxLen);
}

function normalizeUploadedFiles(string $field): array
{
    if (!isset($_FILES[$field])) {
        return [];
    }

    $files = $_FILES[$field];
    if (!isset($files['name']) || !is_array($files['name'])) {
        return [];
    }

    $normalized = [];
    foreach ($files['name'] as $key => $name) {
        $normalized[(string) $key] = [
            'name' => (string) ($name ?? ''),
            'type' => (string) ($files['type'][$key] ?? ''),
            'tmp_name' => (string) ($files['tmp_name'][$key] ?? ''),
            'error' => (int) ($files['error'][$key] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($files['size'][$key] ?? 0),
        ];
    }

    return $normalized;
}

function detectMediaType(string $extension): string
{
    if (in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
        return 'image';
    }
    if ($extension === 'mp4') {
        return 'video';
    }
    return '';
}

function validateUploadedFile(array $file, string $extension): void
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed for: ' . $file['name']);
    }

    $mediaType = detectMediaType($extension);
    if ($mediaType === 'image') {
        if ($file['size'] > MAX_IMAGE_SIZE) {
            throw new RuntimeException('Image too large: ' . $file['name']);
        }
        if (@getimagesize($file['tmp_name']) === false) {
            throw new RuntimeException('Invalid image file: ' . $file['name']);
        }
        return;
    }

    if ($mediaType === 'video') {
        if ($file['size'] > MAX_VIDEO_SIZE) {
            throw new RuntimeException('Video too large: ' . $file['name']);
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? (string) finfo_file($finfo, $file['tmp_name']) : '';
        if ($finfo) {
            finfo_close($finfo);
        }

        if ($mime !== '' && $mime !== 'video/mp4' && $mime !== 'application/octet-stream') {
            throw new RuntimeException('Invalid MP4 file: ' . $file['name']);
        }
        return;
    }

    throw new RuntimeException('Unsupported file type: ' . $file['name']);
}

function optimizeStoredImage(string $path, string $extension, int $maxWidth = MAX_RENDER_IMAGE_WIDTH): void
{
    if ($maxWidth < 1 || !is_file($path)) {
        return;
    }

    $imageInfo = @getimagesize($path);
    if (!is_array($imageInfo)) {
        return;
    }

    $sourceWidth = (int) ($imageInfo[0] ?? 0);
    $sourceHeight = (int) ($imageInfo[1] ?? 0);
    if ($sourceWidth <= 0 || $sourceHeight <= 0 || $sourceWidth <= $maxWidth) {
        return;
    }

    $targetWidth = $maxWidth;
    $targetHeight = (int) max(1, round(($sourceHeight / $sourceWidth) * $targetWidth));

    switch (strtolower($extension)) {
        case 'jpg':
        case 'jpeg':
            if (!function_exists('imagecreatefromjpeg') || !function_exists('imagejpeg')) {
                return;
            }
            $sourceImage = @imagecreatefromjpeg($path);
            break;
        case 'png':
            if (!function_exists('imagecreatefrompng') || !function_exists('imagepng')) {
                return;
            }
            $sourceImage = @imagecreatefrompng($path);
            break;
        default:
            return;
    }

    if ($sourceImage === false) {
        return;
    }

    $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);
    if ($targetImage === false) {
        imagedestroy($sourceImage);
        return;
    }

    if (strtolower($extension) === 'png') {
        imagealphablending($targetImage, false);
        imagesavealpha($targetImage, true);
        $transparent = imagecolorallocatealpha($targetImage, 0, 0, 0, 127);
        imagefilledrectangle($targetImage, 0, 0, $targetWidth, $targetHeight, $transparent);
    }

    imagecopyresampled(
        $targetImage,
        $sourceImage,
        0,
        0,
        0,
        0,
        $targetWidth,
        $targetHeight,
        $sourceWidth,
        $sourceHeight
    );

    if (strtolower($extension) === 'png') {
        imagepng($targetImage, $path, 6);
    } else {
        imagejpeg($targetImage, $path, 85);
    }

    imagedestroy($targetImage);
    imagedestroy($sourceImage);
}

function getJobPath(string $jobId): string
{
    return JOBS_DIR . '/' . $jobId . '.json';
}

function readJob(string $jobId): array
{
    $path = getJobPath($jobId);
    if (!is_file($path)) {
        throw new RuntimeException('Job not found');
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('Cannot read job');
    }

    $job = json_decode($raw, true);
    if (!is_array($job)) {
        throw new RuntimeException('Invalid job file');
    }

    return $job;
}

function writeJob(array $job): void
{
    if (!isset($job['project_id']) || !is_string($job['project_id']) || !safeJobId($job['project_id'])) {
        throw new RuntimeException('Invalid job id');
    }

    ensureDir(JOBS_DIR);
    $path = getJobPath($job['project_id']);
    $data = json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($data === false || file_put_contents($path, $data, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write job file');
    }
}

function listJobsForUser(?array $user, int $limit = 30): array
{
    if (!is_array($user)) {
        return [];
    }

    ensureDir(JOBS_DIR);
    $jobFiles = glob(JOBS_DIR . '/*.json') ?: [];
    $currentUserId = (string) ($user['id'] ?? '');
    $owner = isOwner($user);
    $jobs = [];

    foreach ($jobFiles as $path) {
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            continue;
        }
        $job = json_decode($raw, true);
        if (!is_array($job)) {
            continue;
        }

        $jobUserId = (string) ($job['user_id'] ?? '');
        if (!$owner && ($jobUserId === '' || $jobUserId !== $currentUserId)) {
            continue;
        }

        $projectId = (string) ($job['project_id'] ?? '');
        if (!safeJobId($projectId)) {
            continue;
        }

        $status = (string) ($job['status'] ?? 'unknown');
        $url = '';
        if ($status === 'done' && is_file(OUTPUTS_DIR . '/' . $projectId . '.mp4')) {
            $url = BASE_URL . 'outputs/' . $projectId . '.mp4';
        }

        $jobs[] = [
            'project_id' => $projectId,
            'status' => $status,
            'created_at' => (string) ($job['created_at'] ?? ''),
            'updated_at' => (string) ($job['updated_at'] ?? ''),
            'user_email' => (string) ($job['user_email'] ?? ''),
            'tribute_name' => sanitizeTitleText((string) ($job['tribute_name'] ?? ''), 120),
            'error' => (string) ($job['error'] ?? ''),
            'url' => $url,
        ];
    }

    usort($jobs, static function (array $a, array $b): int {
        $aTs = strtotime((string) ($a['updated_at'] ?? '')) ?: strtotime((string) ($a['created_at'] ?? '')) ?: 0;
        $bTs = strtotime((string) ($b['updated_at'] ?? '')) ?: strtotime((string) ($b['created_at'] ?? '')) ?: 0;
        return $bTs <=> $aTs;
    });

    if ($limit > 0 && count($jobs) > $limit) {
        return array_slice($jobs, 0, $limit);
    }

    return $jobs;
}

function listMusicFiles(): array
{
    ensureDir(MUSIC_DIR);
    $files = glob(MUSIC_DIR . '/*.mp3') ?: [];
    $result = [];
    foreach ($files as $path) {
        $result[] = basename($path);
    }
    sort($result, SORT_NATURAL | SORT_FLAG_CASE);
    return $result;
}

function shellArg(string $value): string
{
    return escapeshellarg($value);
}

function runCommand(array $args): array
{
    $parts = [];
    foreach ($args as $arg) {
        $parts[] = shellArg((string) $arg);
    }

    $command = implode(' ', $parts) . ' 2>&1';
    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);

    return [
        'command' => $command,
        'output' => implode("\n", $output),
        'exit_code' => $exitCode,
    ];
}

function iniSizeToBytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }

    $unit = strtolower(substr($value, -1));
    $numberPart = $value;
    if (in_array($unit, ['k', 'm', 'g'], true)) {
        $numberPart = substr($value, 0, -1);
    } else {
        $unit = '';
    }

    $number = (float) trim($numberPart);
    if ($number <= 0) {
        return 0;
    }

    $bytes = $number;
    if ($unit === 'k') {
        $bytes *= 1024;
    } elseif ($unit === 'm') {
        $bytes *= 1024 * 1024;
    } elseif ($unit === 'g') {
        $bytes *= 1024 * 1024 * 1024;
    }

    return (int) round($bytes);
}

function recursiveDelete(string $path): void
{
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
        $current = $path . '/' . $item;
        if (is_dir($current)) {
            recursiveDelete($current);
        } else {
            @unlink($current);
        }
    }

    @rmdir($path);
}

function ensureSession(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function normalizeEmail(string $email): string
{
    return strtolower(trim($email));
}

function usersFilePath(): string
{
    ensureDir(DATA_DIR);
    if (!is_file(USERS_FILE)) {
        file_put_contents(USERS_FILE, "[]\n", LOCK_EX);
    }
    return USERS_FILE;
}

function readUsers(): array
{
    $path = usersFilePath();
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    return $decoded;
}

function writeUsers(array $users): void
{
    $path = usersFilePath();
    $json = json_encode(array_values($users), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($path, $json, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write users file');
    }
}

function saveUser(array $updatedUser): void
{
    $userId = (string) ($updatedUser['id'] ?? '');
    if ($userId === '') {
        throw new RuntimeException('Invalid user id');
    }

    $users = readUsers();
    $updated = false;
    foreach ($users as $i => $user) {
        if (!is_array($user)) {
            continue;
        }
        if ((string) ($user['id'] ?? '') === $userId) {
            $users[$i] = $updatedUser;
            $updated = true;
            break;
        }
    }

    if (!$updated) {
        throw new RuntimeException('User not found');
    }
    writeUsers($users);
}

function sanitizeRole(string $role): string
{
    $normalized = strtolower(trim($role));
    if ($normalized === 'owner' || $normalized === 'client') {
        return $normalized;
    }
    return 'client';
}

function usersExist(): bool
{
    return count(readUsers()) > 0;
}

function findUserByEmail(string $email): ?array
{
    $needle = normalizeEmail($email);
    if ($needle === '') {
        return null;
    }

    foreach (readUsers() as $user) {
        if (!is_array($user)) {
            continue;
        }
        if (normalizeEmail((string) ($user['email'] ?? '')) === $needle) {
            return $user;
        }
    }
    return null;
}

function findUserById(string $userId): ?array
{
    foreach (readUsers() as $user) {
        if (!is_array($user)) {
            continue;
        }
        if ((string) ($user['id'] ?? '') === $userId) {
            return $user;
        }
    }
    return null;
}

function createUser(string $email, string $password, string $role, string $createdById = '', array $profile = []): array
{
    $normalizedEmail = normalizeEmail($email);
    if (!filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Invalid email');
    }
    if (strlen($password) < 8) {
        throw new RuntimeException('Password must contain at least 8 characters');
    }

    $users = readUsers();
    foreach ($users as $user) {
        if (normalizeEmail((string) ($user['email'] ?? '')) === $normalizedEmail) {
            throw new RuntimeException('Email already in use');
        }
    }

    $record = [
        'id' => generateId(12),
        'email' => $normalizedEmail,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => sanitizeRole($role),
        'created_at' => gmdate('c'),
        'created_by' => $createdById,
        'profile' => [
            'client_first_name' => sanitizeTitleText((string) ($profile['client_first_name'] ?? ''), 80),
            'client_last_name' => sanitizeTitleText((string) ($profile['client_last_name'] ?? ''), 80),
            'tribute_name' => sanitizeTitleText((string) ($profile['tribute_name'] ?? ''), 120),
        ],
    ];
    $users[] = $record;
    writeUsers($users);

    return $record;
}

function authenticateUser(string $email, string $password): ?array
{
    $user = findUserByEmail($email);
    if ($user === null) {
        return null;
    }
    $hash = (string) ($user['password_hash'] ?? '');
    if ($hash === '' || !password_verify($password, $hash)) {
        return null;
    }
    return $user;
}

function resetEmailFrom(): string
{
    if (defined('MAIL_FROM') && is_string(MAIL_FROM) && trim(MAIL_FROM) !== '') {
        return trim(MAIL_FROM);
    }
    return 'noreply@localhost';
}

function issuePasswordResetToken(string $email): ?string
{
    $user = findUserByEmail($email);
    if ($user === null) {
        return null;
    }

    $token = bin2hex(random_bytes(24));
    $user['reset_token_hash'] = hash('sha256', $token);
    $user['reset_expires_at'] = gmdate('c', time() + 3600);
    saveUser($user);
    return $token;
}

function buildPasswordResetLink(string $token, string $type = 'reset'): string
{
    $resetType = $type === 'invite' ? 'invite' : 'reset';
    $link = BASE_URL . '?reset_token=' . urlencode($token) . '&reset_type=' . urlencode($resetType);
    if (strpos($link, 'http://') !== 0 && strpos($link, 'https://') !== 0) {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $link = $scheme . '://' . $host . $link;
    }
    return $link;
}

function encodeMailHeaderUtf8(string $text): string
{
    if (function_exists('mb_encode_mimeheader')) {
        $encoded = mb_encode_mimeheader($text, 'UTF-8', 'B', "\r\n");
        if (is_string($encoded) && $encoded !== '') {
            return $encoded;
        }
    }
    return '=?UTF-8?B?' . base64_encode($text) . '?=';
}

function sendUtf8Mail(string $toEmail, string $subject, string $body): bool
{
    $from = resetEmailFrom();
    $headers = [
        'From: ' . $from,
        'Reply-To: ' . $from,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];

    return @mail($toEmail, encodeMailHeaderUtf8($subject), $body, implode("\r\n", $headers));
}

function sendPasswordResetEmail(string $toEmail, string $token): bool
{
    $link = buildPasswordResetLink($token, 'reset');
    $subject = 'Réinitialisation de votre mot de passe';
    $body = "Bonjour,\n\nPour réinitialiser votre mot de passe, utilisez ce lien:\n" . $link . "\n\nCe lien expire dans 1 heure.\n";
    return sendUtf8Mail($toEmail, $subject, $body);
}

function sendClientInviteEmail(string $toEmail, string $token): bool
{
    $link = buildPasswordResetLink($token, 'invite');
    $subject = 'Invitation - activez votre compte client';
    $body = "Bonjour,\n\nUn compte client a été créé pour vous.\nCliquez sur ce lien pour définir votre mot de passe:\n" . $link . "\n\nCe lien expire dans 1 heure.\n";
    return sendUtf8Mail($toEmail, $subject, $body);
}

function resetPasswordByToken(string $token, string $newPassword): bool
{
    if (strlen($newPassword) < 8) {
        throw new RuntimeException('Le mot de passe doit contenir au moins 8 caractères');
    }

    $token = trim($token);
    if ($token === '') {
        return false;
    }
    $tokenHash = hash('sha256', $token);
    $now = time();

    $users = readUsers();
    $updated = false;
    foreach ($users as $i => $user) {
        if (!is_array($user)) {
            continue;
        }
        if ((string) ($user['reset_token_hash'] ?? '') !== $tokenHash) {
            continue;
        }

        $expiresAt = (string) ($user['reset_expires_at'] ?? '');
        $expiresTs = $expiresAt !== '' ? strtotime($expiresAt) : false;
        if ($expiresTs === false || $expiresTs < $now) {
            return false;
        }

        $users[$i]['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
        unset($users[$i]['reset_token_hash'], $users[$i]['reset_expires_at']);
        $updated = true;
        break;
    }

    if ($updated) {
        writeUsers($users);
    }
    return $updated;
}

function loginUser(array $user): void
{
    ensureSession();
    $_SESSION['user_id'] = (string) ($user['id'] ?? '');
}

function logoutUser(): void
{
    ensureSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
}

function currentUser(): ?array
{
    ensureSession();
    $userId = (string) ($_SESSION['user_id'] ?? '');
    if ($userId === '') {
        return null;
    }
    return findUserById($userId);
}

function isOwner(?array $user): bool
{
    return is_array($user) && sanitizeRole((string) ($user['role'] ?? '')) === 'owner';
}
