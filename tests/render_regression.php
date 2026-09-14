<?php
declare(strict_types=1);

// Run with PHP CLI and ffmpeg/ffprobe on PATH. All data stays in a private
// temporary directory; the production queue and configuration are never read.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$repo = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/video-builder-test-' . bin2hex(random_bytes(8));
mkdir($fixture, 0700);
mkdir($fixture . '/server', 0700);
copy($repo . '/server/common.php', $fixture . '/server/common.php');
copy($repo . '/server/process_jobs.php', $fixture . '/server/process_jobs.php');
file_put_contents($fixture . '/server/config.php', '<?php const BASE_URL = "http://localhost/";'
    . ' const TITLE_FONT_FILE = ' . var_export($repo . '/server/fonts/Satisfy-Regular.ttf', true) . ';');
require $fixture . '/server/process_jobs.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS: {$message}\n";
}

function videoStream(string $path): array
{
    $result = runCommand([FFPROBE_BIN, '-v', 'error', '-select_streams', 'v:0',
        '-show_entries', 'stream=r_frame_rate,time_base,duration,nb_frames', '-of', 'json', $path]);
    if ($result['exit_code'] !== 0) {
        throw new RuntimeException($result['output']);
    }
    return json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR)['streams'][0];
}

try {
    runFfmpeg([FFMPEG_BIN, '-nostdin', '-y', '-f', 'lavfi', '-i', 'color=c=navy:s=640x360:r=25',
        '-frames:v', '1', '-threads', '1', $fixture . '/photo.jpg']);
    runFfmpeg([FFMPEG_BIN, '-nostdin', '-y', '-f', 'lavfi', '-i', 'color=c=navy:s=640x360:r=60',
        '-t', '1', '-c:v', 'libx264', '-threads', '1', '-pix_fmt', 'yuv420p', $fixture . '/clip.mp4']);

    foreach (['photo.jpg', 'clip.mp4'] as $source) {
        $out = $fixture . '/title-' . $source . '.mp4';
        makeTitlePart($out, 'END TEST', '', 4, 'fade', 'fade', '', false, $fixture . '/' . $source);
        $stream = videoStream($out);
        check($stream['r_frame_rate'] === '30/1', "{$source}: title is 30 fps");
        check($stream['time_base'] === '1/15360', "{$source}: title matches media time base");
        check(abs((float) $stream['duration'] - 4.0) < 0.05, "{$source}: complete title duration");
    }

    // Reproduce the reported layout: 20 three-second photos, five-second intro,
    // seven-second outro. Reuse one rendered photo segment to keep this fast.
    makeTitlePart($fixture . '/intro.mp4', 'INTRO', '', 5, 'fade', 'fade', '', true, $fixture . '/photo.jpg');
    makeTitlePart($fixture . '/outro.mp4', 'END TEST', '', 7, 'fade', 'fade', '', false, $fixture . '/photo.jpg');
    runFfmpeg([FFMPEG_BIN, '-nostdin', '-y', '-loop', '1', '-i', $fixture . '/photo.jpg',
        '-vf', 'scale=1920:1080,setsar=1', '-t', '3', '-r', '30', '-c:v', 'libx264',
        '-preset', 'veryfast', '-threads', '1', '-pix_fmt', 'yuv420p', '-an', $fixture . '/media.mp4']);
    $lines = [makeConcatListLine($fixture . '/intro.mp4')];
    for ($i = 0; $i < 20; $i++) {
        $lines[] = makeConcatListLine($fixture . '/media.mp4');
    }
    $lines[] = makeConcatListLine($fixture . '/outro.mp4');
    file_put_contents($fixture . '/list.txt', implode("\n", $lines) . "\n");
    runFfmpeg([FFMPEG_BIN, '-nostdin', '-y', '-f', 'concat', '-safe', '0', '-i', $fixture . '/list.txt',
        '-r', '30', '-c:v', 'libx264', '-preset', 'veryfast', '-threads', '1', '-pix_fmt', 'yuv420p',
        '-an', $fixture . '/montage.mp4']);
    $stream = videoStream($fixture . '/montage.mp4');
    check(abs((float) $stream['duration'] - 72.0) < 0.05, 'montage keeps all 72 seconds');
    check((int) $stream['nb_frames'] === 2160, 'montage keeps all 2160 frames');

    // Compare the actual closing card in the montage with its standalone render.
    // The former timestamp bug dropped this card despite a successful job status.
    $compare = runCommand([FFMPEG_BIN, '-nostdin', '-v', 'info', '-ss', '67', '-i', $fixture . '/montage.mp4',
        '-ss', '2', '-i', $fixture . '/outro.mp4', '-lavfi', 'ssim', '-frames:v', '1', '-f', 'null', '-']);
    preg_match('/All:([0-9.]+)/', $compare['output'], $score);
    check($compare['exit_code'] === 0 && isset($score[1]) && (float) $score[1] > 0.99,
        'closing card survives concatenation visually');
    echo "Rendering regression checks passed.\n";
} finally {
    recursiveDelete($fixture);
}
