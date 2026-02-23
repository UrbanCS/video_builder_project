<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

try {
    $jobId = (string) ($_GET['job_id'] ?? '');
    if (!safeJobId($jobId)) {
        jsonResponse(['error' => 'Invalid job_id'], 400);
    }

    $job = readJob($jobId);
    $status = (string) ($job['status'] ?? 'unknown');

    if ($status === 'done') {
        $url = BASE_URL . 'outputs/' . $jobId . '.mp4';
        if (!is_file(OUTPUTS_DIR . '/' . $jobId . '.mp4')) {
            jsonResponse(['status' => 'done', 'warning' => 'Output file missing']);
        }
        jsonResponse([
            'status' => 'done',
            'url' => $url,
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
