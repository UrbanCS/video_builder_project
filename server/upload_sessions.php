<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

// Staging lives under the already HTTP-protected uploads directory, not the queue.
const UPLOAD_SESSION_TTL = 86400;

function uploadSessionPath(string $token): string
{
    if (!preg_match('/^[a-f0-9]{48}$/D', $token)) {
        throw new RuntimeException('Invalid upload token');
    }
    return UPLOADS_DIR . '/batch_' . $token;
}

function openUploadSession(string $token, string $userId): array
{
    $dir = uploadSessionPath($token);
    $handle = @fopen($dir . '/manifest.json', 'r+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        throw new RuntimeException('Envoi introuvable. Veuillez réessayer.');
    }
    $state = json_decode((string) stream_get_contents($handle), true);
    if (!is_array($state) || $userId === '' || ($state['user_id'] ?? '') !== $userId
        || (int) ($state['created_at'] ?? 0) < time() - UPLOAD_SESSION_TTL) {
        fclose($handle);
        throw new RuntimeException('Envoi expiré ou non autorisé. Veuillez réessayer.');
    }
    return [$dir, $handle, $state];
}

function saveUploadSession($handle, array $state): void
{
    $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    rewind($handle);
    if (!ftruncate($handle, 0) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
        throw new RuntimeException('Unable to save upload state');
    }
}

function requireUploadHeader(): void
{
    // Cross-origin forms cannot set this header; no CORS access is granted.
    if (($_SERVER['HTTP_X_VIDEO_UPLOAD'] ?? '') !== '1') {
        jsonResponse(['error' => 'Invalid upload request'], 403);
    }
}

function cleanupExpiredUploadSessions(): void
{
    foreach (glob(UPLOADS_DIR . '/batch_*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (!preg_match('/^batch_[a-f0-9]{48}$/D', basename($dir)) || is_link($dir)) {
            continue;
        }
        $manifest = $dir . '/manifest.json';
        if (!is_file($manifest) || filemtime($manifest) >= time() - UPLOAD_SESSION_TTL) {
            continue;
        }
        $handle = @fopen($manifest, 'r+');
        if ($handle !== false) {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                // Only this feature's expired staging files, never project media.
                foreach (glob($dir . '/*') ?: [] as $file) {
                    if (is_file($file) && !is_link($file)) {
                        unlink($file);
                    }
                }
                @rmdir($dir);
            }
            fclose($handle);
        }
    }
}
