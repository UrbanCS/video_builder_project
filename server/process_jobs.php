<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

const FFMPEG_BIN = 'ffmpeg';
const FFPROBE_BIN = 'ffprobe';

function setJobStatus(array $job, string $status, ?string $error = null): array
{
    $job['status'] = $status;
    $job['updated_at'] = gmdate('c');
    if ($error !== null) {
        $job['error'] = $error;
    } else {
        unset($job['error']);
    }
    writeJob($job);
    return $job;
}

function ffprobeDuration(string $inputPath): float
{
    $result = runCommand([
        FFPROBE_BIN,
        '-v', 'error',
        '-show_entries', 'format=duration',
        '-of', 'default=noprint_wrappers=1:nokey=1',
        $inputPath,
    ]);

    if ($result['exit_code'] !== 0) {
        throw new RuntimeException('ffprobe failed: ' . $result['output']);
    }

    $duration = (float) trim($result['output']);
    if ($duration <= 0) {
        throw new RuntimeException('Unable to read media duration: ' . $inputPath);
    }

    return $duration;
}

function runFfmpeg(array $args): void
{
    $result = runCommand($args);
    if ($result['exit_code'] !== 0) {
        throw new RuntimeException('FFmpeg command failed: ' . $result['output']);
    }
}

function makeConcatListLine(string $path): string
{
    $escaped = str_replace("'", "'\\''", $path);
    return "file '{$escaped}'";
}

function processJob(array $job): array
{
    $projectId = $job['project_id'] ?? '';
    if (!is_string($projectId) || !safeJobId($projectId)) {
        throw new RuntimeException('Invalid project_id');
    }

    $projectDir = UPLOADS_DIR . '/' . $projectId;
    $workDir = $projectDir . '/work';
    ensureDir($workDir);
    ensureDir(OUTPUTS_DIR);

    $media = $job['media'] ?? [];
    if (!is_array($media) || count($media) === 0) {
        throw new RuntimeException('Empty media list');
    }

    if (count($media) > MAX_FILES) {
        throw new RuntimeException('Too many media items');
    }

    $totalDuration = 0.0;
    $parts = [];

    foreach ($media as $index => $item) {
        if (!is_array($item)) {
            throw new RuntimeException('Invalid media item');
        }

        $type = (string) ($item['type'] ?? '');
        $filename = basename((string) ($item['file'] ?? ''));
        $inputPath = $projectDir . '/' . $filename;

        if (!is_file($inputPath)) {
            throw new RuntimeException('Missing input file: ' . $filename);
        }

        $partPath = $workDir . '/part_' . str_pad((string) $index, 3, '0', STR_PAD_LEFT) . '.mp4';

        if ($type === 'image') {
            $duration = (int) ($item['duration'] ?? DEFAULT_IMAGE_DURATION);
            if ($duration < 1 || $duration > MAX_IMAGE_DURATION) {
                throw new RuntimeException('Invalid image duration for ' . $filename);
            }

            runFfmpeg([
                FFMPEG_BIN,
                '-y',
                '-loop', '1',
                '-t', (string) $duration,
                '-i', $inputPath,
                '-vf', 'scale=1920:1080:force_original_aspect_ratio=decrease,pad=1920:1080:(ow-iw)/2:(oh-ih)/2',
                '-r', '30',
                '-threads', '1',
                '-c:v', 'libx264',
                '-preset', 'veryfast',
                '-pix_fmt', 'yuv420p',
                '-an',
                $partPath,
            ]);

            $totalDuration += $duration;
            $parts[] = $partPath;
            continue;
        }

        if ($type === 'video') {
            runFfmpeg([
                FFMPEG_BIN,
                '-y',
                '-i', $inputPath,
                '-vf', 'scale=1920:1080:force_original_aspect_ratio=decrease,pad=1920:1080:(ow-iw)/2:(oh-ih)/2',
                '-r', '30',
                '-threads', '1',
                '-c:v', 'libx264',
                '-preset', 'veryfast',
                '-crf', '23',
                '-c:a', 'aac',
                '-b:a', '128k',
                $partPath,
            ]);

            $totalDuration += ffprobeDuration($partPath);
            $parts[] = $partPath;
            continue;
        }

        throw new RuntimeException('Unsupported media type: ' . $type);
    }

    if ($totalDuration > MAX_TOTAL_DURATION) {
        throw new RuntimeException('Total duration exceeds ' . MAX_TOTAL_DURATION . ' seconds');
    }

    if (count($parts) === 0) {
        throw new RuntimeException('No parts generated');
    }

    $listPath = $workDir . '/list.txt';
    $lines = [];
    foreach ($parts as $path) {
        $lines[] = makeConcatListLine($path);
    }

    if (file_put_contents($listPath, implode("\n", $lines) . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Unable to write list.txt');
    }

    $mergedPath = $workDir . '/merged.mp4';
    runFfmpeg([
        FFMPEG_BIN,
        '-y',
        '-f', 'concat',
        '-safe', '0',
        '-i', $listPath,
        '-threads', '1',
        '-c', 'copy',
        $mergedPath,
    ]);

    $finalPath = OUTPUTS_DIR . '/' . $projectId . '.mp4';
    $music = sanitizeMusicFilename((string) ($job['music'] ?? ''));

    if ($music !== '') {
        $musicPath = MUSIC_DIR . '/' . $music;
        if (!is_file($musicPath)) {
            throw new RuntimeException('Selected music not found: ' . $music);
        }

        runFfmpeg([
            FFMPEG_BIN,
            '-y',
            '-i', $mergedPath,
            '-stream_loop', '-1',
            '-i', $musicPath,
            '-map', '0:v:0',
            '-map', '1:a:0',
            '-threads', '1',
            '-c:v', 'copy',
            '-c:a', 'aac',
            '-shortest',
            $finalPath,
        ]);
    } else {
        if (!rename($mergedPath, $finalPath)) {
            throw new RuntimeException('Unable to move final video to outputs');
        }
    }

    recursiveDelete($workDir);

    $job['status'] = 'done';
    $job['updated_at'] = gmdate('c');
    $job['url'] = '/outputs/' . $projectId . '.mp4';
    writeJob($job);

    $projectJobPath = $projectDir . '/job.json';
    $jobJson = json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($jobJson !== false) {
        file_put_contents($projectJobPath, $jobJson, LOCK_EX);
    }

    return $job;
}

function main(): void
{
    ensureDir(JOBS_DIR);

    $lockPath = JOBS_DIR . '/.process.lock';
    $lockHandle = fopen($lockPath, 'c');
    if ($lockHandle === false) {
        throw new RuntimeException('Cannot open lock file');
    }

    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        fclose($lockHandle);
        echo "Worker already running\n";
        return;
    }

    $jobFiles = glob(JOBS_DIR . '/*.json') ?: [];
    sort($jobFiles, SORT_NATURAL);

    foreach ($jobFiles as $jobFile) {
        $raw = file_get_contents($jobFile);
        if ($raw === false) {
            continue;
        }

        $job = json_decode($raw, true);
        if (!is_array($job)) {
            continue;
        }

        if (($job['status'] ?? '') !== 'pending') {
            continue;
        }

        try {
            $job = setJobStatus($job, 'processing');
            $job = processJob($job);
            echo sprintf("[%s] done\n", $job['project_id']);
        } catch (Throwable $e) {
            $job['status'] = 'failed';
            $job['updated_at'] = gmdate('c');
            $job['error'] = $e->getMessage();
            writeJob($job);

            $projectId = (string) ($job['project_id'] ?? '');
            if (safeJobId($projectId)) {
                $projectPath = UPLOADS_DIR . '/' . $projectId . '/job.json';
                $jobJson = json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                if ($jobJson !== false) {
                    file_put_contents($projectPath, $jobJson, LOCK_EX);
                }
            }

            echo sprintf("[%s] failed: %s\n", $projectId !== '' ? $projectId : 'unknown', $e->getMessage());
        }
    }

    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}

try {
    main();
} catch (Throwable $e) {
    fwrite(STDERR, 'Fatal worker error: ' . $e->getMessage() . "\n");
    exit(1);
}
