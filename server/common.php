<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

const ROOT_DIR = __DIR__ . '/..';
const UPLOADS_DIR = ROOT_DIR . '/uploads';
const OUTPUTS_DIR = ROOT_DIR . '/outputs';
const JOBS_DIR = ROOT_DIR . '/jobs';
const MUSIC_DIR = ROOT_DIR . '/music';

const MAX_FILES = 40;
const MAX_TOTAL_DURATION = 600;
const DEFAULT_IMAGE_DURATION = 3;
const MAX_IMAGE_DURATION = 10;
const MAX_IMAGE_SIZE = 30 * 1024 * 1024;   // 30MB
const MAX_VIDEO_SIZE = 150 * 1024 * 1024;  // 150MB

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
