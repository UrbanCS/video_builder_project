<?php
declare(strict_types=1);

die('STATUS FILE TEST');

require __DIR__ . '/common.php';

try {
    ensureSession();
    $currentUser = currentUser();
    if ($currentUser === null) {
        jsonResponse(['error' => 'Authentication required'], 401);
    }

    $jobId = (string) ($_GET['job_id'] ?? '');
    if (!safeJobId($jobId)) {
        jsonResponse(['error' => 'Invalid job_id'], 400);
    }

    $job = readJob($jobId);
    $jobUserId = (string) ($job['user_id'] ?? '');
    $currentUserId = (string) ($currentUser['id'] ?? '');
    if (($jobUserId === '' && !isOwner($currentUser)) || ($jobUserId !== '' && $jobUserId !== $currentUserId && !isOwner($currentUser))) {
        jsonResponse(['error' => 'Forbidden'], 403);
    }
    $status = (string) ($job['status'] ?? 'unknown');

    if ($status === 'done') {
        $url = BASE_URL . 'outputs/' . $jobId . '.mp4';
        if (!is_file(OUTPUTS_DIR . '/' . $jobId . '.mp4')) {
            jsonResponse(['status' => 'done', 'warning' => 'Output file missing']);
        }
        jsonResponse([
            'status' => 'done',
            'url' => $url,
<<<<<<< HEAD
=======
            'debug_base_url' => BASE_URL,
            'debug_config_file' => realpath(__DIR__ . '/config.php'),
            'debug_script' => $_SERVER['SCRIPT_FILENAME'] ?? '',
>>>>>>> a11a78e (Add video builder rendering and admin history improvements)
        ]);
    }

    if ($status === 'failed') {
        jsonResponse([
            'status' => 'failed',
            'error' => (string) ($job['error'] ?? 'Unknown processing error'),
        ]);
    }

    jsonResponse(['status' => $status]);
} catch (Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
