<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

const FFMPEG_BIN = 'ffmpeg';
const FFPROBE_BIN = 'ffprobe';
const DRAWTEXT_FILTER = 'drawtext';
const SUBTITLES_FILTER = 'subtitles';
const FFMPEG_TIMEOUT_SECONDS = 300;
const OUTRO_EXTRA_SECONDS = 2;

function titleFontFamily(): string
{
    if (defined('TITLE_FONT_FAMILY') && is_string(TITLE_FONT_FAMILY) && trim(TITLE_FONT_FAMILY) !== '') {
        return trim(TITLE_FONT_FAMILY);
    }
    return 'Satisfy';
}

function titleFontFile(): string
{
    if (defined('TITLE_FONT_FILE') && is_string(TITLE_FONT_FILE) && trim(TITLE_FONT_FILE) !== '') {
        $file = trim(TITLE_FONT_FILE);
        if (is_file($file)) {
            return $file;
        }
    }
    return '';
}

function titleFontsDir(): string
{
    $fontFile = titleFontFile();
    if ($fontFile !== '') {
        $dir = dirname($fontFile);
        if (is_dir($dir)) {
            return $dir;
        }
    }
    return '';
}

function titleDrawtextFontOption(): string
{
    $fontFile = titleFontFile();
    if ($fontFile !== '') {
        return "fontfile='" . ffmpegEscapeText($fontFile) . "'";
    }
    return "font='" . ffmpegEscapeText(titleFontFamily()) . "'";
}

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
    $result = runCommand(appendLinuxTimeout($args, ffmpegTimeoutSeconds()));
    if ($result['exit_code'] !== 0) {
        throw new RuntimeException('FFmpeg command failed: ' . $result['output']);
    }
}

function ffmpegTimeoutSeconds(): int
{
    if (defined('FFMPEG_JOB_TIMEOUT_SECONDS')) {
        $configured = (int) FFMPEG_JOB_TIMEOUT_SECONDS;
        if ($configured > 0) {
            return $configured;
        }
    }
    return FFMPEG_TIMEOUT_SECONDS;
}

function appendLinuxTimeout(array $args, int $seconds): array
{
    if ($seconds <= 0) {
        return $args;
    }

    if (DIRECTORY_SEPARATOR === '\\') {
        return $args;
    }

    static $timeoutCmd = null;
    if ($timeoutCmd === null) {
        foreach (['/usr/bin/timeout', '/bin/timeout'] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                $timeoutCmd = $candidate;
                break;
            }
        }
        if ($timeoutCmd === null) {
            $timeoutCmd = '';
        }
    }

    if ($timeoutCmd === '') {
        return $args;
    }

    // SIGTERM after N sec, then force-kill 10 sec later if still alive.
    return array_merge([$timeoutCmd, '-k', '10', (string) $seconds], $args);
}

function ffmpegSupportsFilter(string $filterName): bool
{
    static $cache = [];
    if (array_key_exists($filterName, $cache)) {
        return (bool) $cache[$filterName];
    }

    $result = runCommand([
        FFMPEG_BIN,
        '-hide_banner',
        '-filters',
    ]);

    if ($result['exit_code'] !== 0) {
        $cache[$filterName] = false;
        return false;
    }

    $pattern = '/\b' . preg_quote($filterName, '/') . '\b/i';
    $cache[$filterName] = (bool) preg_match($pattern, $result['output']);
    return (bool) $cache[$filterName];
}

function makeConcatListLine(string $path): string
{
    $escaped = str_replace("'", "'\\''", $path);
    return "file '{$escaped}'";
}

function ffmpegEscapeText(string $value): string
{
    $escaped = str_replace('\\', '\\\\', $value);
    $escaped = str_replace(':', '\\:', $escaped);
    $escaped = str_replace("'", "\\'", $escaped);
    return $escaped;
}

function resolveBackgroundPath(string $backgroundFile): string
{
    return resolvePresentationBackgroundPath($backgroundFile);
}

function transitionTail(string $transition, float $duration): string
{
    if (!in_array($transition, ['fade', 'crossfade'], true) || $duration < 1.2) {
        return '';
    }
    $fadeDuration = $transition === 'crossfade' ? min(0.8, $duration / 3.0) : min(0.5, $duration / 4.0);
    $fadeOutStart = max(0.0, $duration - $fadeDuration);
    return sprintf(',fade=t=in:st=0:d=%.3F,fade=t=out:st=%.3F:d=%.3F', $fadeDuration, $fadeOutStart, $fadeDuration);
}

function transitionTailOutOnly(string $transition, float $duration): string
{
    if (!in_array($transition, ['fade', 'crossfade'], true) || $duration < 1.2) {
        return '';
    }
    $fadeDuration = $transition === 'crossfade' ? min(0.8, $duration / 3.0) : min(0.5, $duration / 4.0);
    $fadeOutStart = max(0.0, $duration - $fadeDuration);
    return sprintf(',fade=t=out:st=%.3F:d=%.3F', $fadeOutStart, $fadeDuration);
}

function imageVisualFilter(string $transition, string $animation, int $duration): string
{
    if ($animation === 'random') {
        $choices = ['zoom_in', 'zoom_out', 'pan_right', 'pan_left', 'rotate', 'none'];
        $animation = $choices[random_int(0, count($choices) - 1)];
    }

    $frames = max(1, $duration * 30);
    $enterFrames = max(10, (int) round($frames * 0.30));
    $enterSec = max(0.6, min(1.0, $duration * 0.30));
    if ($animation === 'pan_right') {
        return sprintf(
            "scale=2300:1294:force_original_aspect_ratio=increase,crop=1920:1080:x='(in_w-1920)*min(1,max(0,n/%d))':y='(in_h-1080)/2'",
            $enterFrames
        );
    }
    if ($animation === 'slide_premium') {
        $enterFrames = max(10, (int) round($frames * 0.28));
        return sprintf(
            "scale=2300:1294:force_original_aspect_ratio=increase,crop=1920:1080:x='(in_w-1920)*if(lt(n,%d),1-pow(1-n/%d,3),1)':y='(in_h-1080)/2'",
            $enterFrames,
            $enterFrames
        );
    }
    if ($animation === 'pan_left') {
        return sprintf(
            "scale=2300:1294:force_original_aspect_ratio=increase,crop=1920:1080:x='(in_w-1920)*(1-min(1,max(0,n/%d)))':y='(in_h-1080)/2'",
            $enterFrames
        );
    }
    if ($animation === 'zoom_in') {
        return sprintf(
            "scale='trunc(2300*(1+0.10*min(1,t/%.3F))/2)*2':'trunc(1294*(1+0.10*min(1,t/%.3F))/2)*2':eval=frame:force_original_aspect_ratio=increase,crop=1920:1080:x='(in_w-1920)/2':y='(in_h-1080)/2'",
            $enterSec,
            $enterSec
        );
    }
    if ($animation === 'zoom_out') {
        return sprintf(
            "scale='trunc(2300*(1.10-0.10*min(1,t/%.3F))/2)*2':'trunc(1294*(1.10-0.10*min(1,t/%.3F))/2)*2':eval=frame:force_original_aspect_ratio=increase,crop=1920:1080:x='(in_w-1920)/2':y='(in_h-1080)/2'",
            $enterSec,
            $enterSec
        );
    }
    if ($animation === 'rotate') {
        return sprintf(
            "scale=2200:1238:force_original_aspect_ratio=increase,crop=1920:1080,rotate='(1-min(1,t/%.3F))*0.06':c=black@0:ow=rotw(iw):oh=roth(ih),scale=1920:1080:force_original_aspect_ratio=increase,crop=1920:1080",
            $enterSec
        );
    }
    if ($transition === 'slide') {
        return sprintf(
            "scale=2300:1294:force_original_aspect_ratio=increase,crop=1920:1080:x='(in_w-1920)*min(1,max(0,n/%d))':y='(in_h-1080)/2'",
            $frames
        );
    }

    return 'scale=1824:1026:force_original_aspect_ratio=decrease';
}

function backgroundImageAnimationSpec(string $animation, int $duration): array
{
    $centerX = '(W-w)/2';
    $centerY = '(H-h)/2';
    $enterSec = min(1.0, max(0.45, $duration * 0.18));

    // Keep media proportional and visibly inside the frame to expose the background.
    $fit95 = 'scale=1824:1026:force_original_aspect_ratio=decrease';
    $xExpr = $centerX;
    $yExpr = $centerY;

    if ($animation === 'pan_right') {
        $xExpr = "if(lt(t,{$enterSec}),{$centerX} + (1 - t/{$enterSec})*(W+w),{$centerX})";
    } elseif ($animation === 'pan_left') {
        $xExpr = "if(lt(t,{$enterSec}),{$centerX} - (1 - t/{$enterSec})*(W+w),{$centerX})";
    } elseif ($animation === 'slide_premium') {
        $enterSec = 0.85;
        $fit95 = "scale='trunc(iw*(0.92 + 0.08*min(1,t/{$enterSec}))/2)*2':'trunc(ih*(0.92 + 0.08*min(1,t/{$enterSec}))/2)*2':eval=frame,scale=1824:1026:force_original_aspect_ratio=decrease";
        $xExpr = "if(lt(t,{$enterSec}),{$centerX} + (1 - min(1,t/{$enterSec}))*(W+w),{$centerX})";
        $yExpr = "if(lt(t,{$enterSec}),{$centerY} + (1 - min(1,t/{$enterSec}))*22,{$centerY})";
    } elseif ($animation === 'zoom_in') {
        $fit95 = "scale='trunc(iw*(0.90 + 0.10*min(1,t/{$enterSec}))/2)*2':'trunc(ih*(0.90 + 0.10*min(1,t/{$enterSec}))/2)*2':eval=frame,scale=1824:1026:force_original_aspect_ratio=decrease";
    } elseif ($animation === 'zoom_out') {
        $fit95 = "scale='trunc(iw*(1.10 - 0.10*min(1,t/{$enterSec}))/2)*2':'trunc(ih*(1.10 - 0.10*min(1,t/{$enterSec}))/2)*2':eval=frame,scale=1824:1026:force_original_aspect_ratio=decrease";
    } elseif ($animation === 'rotate') {
        // Keep rotation subtle for better stability on constrained hosts.
        $fit95 = "rotate='(1-min(1,t/{$enterSec}))*0.03':c=none:ow=rotw(iw):oh=roth(ih),scale=1824:1026:force_original_aspect_ratio=decrease";
    }

    return [
        'fg_filter' => $fit95,
        'x' => $xExpr,
        'y' => $yExpr,
        'enter_sec' => $enterSec,
    ];
}

function videoVisualFilter(string $transition, float $duration): string
{
    if ($transition === 'slide') {
        $seconds = max(1.0, $duration);
        return sprintf(
            "scale=2300:1294:force_original_aspect_ratio=increase,crop=1920:1080:x='(in_w-1920)*min(1,max(0,t/%.3F))':y='(in_h-1080)/2'",
            $seconds
        );
    }

    return 'scale=1824:1026:force_original_aspect_ratio=decrease';
}

function selfBlurBackgroundFilterComplex(string $fgFilter, string $overlayX, string $overlayY, string $transitionTail): string
{
    return "[0:v]split=2[bgsrc][fgsrc];"
        . "[bgsrc]scale=1920:1080:force_original_aspect_ratio=increase,crop=1920:1080,boxblur=24:8,eq=brightness=-0.08:saturation=1.08[bg];"
        . "[fgsrc]{$fgFilter}[fg];"
        . "[bg][fg]overlay=x='{$overlayX}':y='{$overlayY}':format=auto{$transitionTail}";
}

function titleBaseTransitionFilter(float $duration, string $transition, string $titleAnimation, bool $suppressFadeOut = false): string
{
    if ($suppressFadeOut) {
        $fadeIn = min(0.7, max(0.45, $duration / 6.0));
        if ($titleAnimation === 'zoom_in') {
            $zoomSeconds = min(1.2, max(0.7, $duration / 4.0));
            return sprintf(
                ",scale='1920+120*min(1,t/%.3F)':-1:eval=frame,crop=1920:1080:(in_w-1920)/2:(in_h-1080)/2,fade=t=in:st=0:d=%.3F",
                $zoomSeconds,
                $fadeIn
            );
        }
        return sprintf(',fade=t=in:st=0:d=%.3F', $fadeIn);
    }

    if ($titleAnimation === 'none') {
        return transitionTail($transition, $duration);
    }
    if ($titleAnimation === 'zoom_in') {
        $zoomSeconds = min(1.2, max(0.7, $duration / 4.0));
        $tail = transitionTail($transition, $duration);
        return sprintf(
            ",scale='1920+120*min(1,t/%.3F)':-1:eval=frame,crop=1920:1080:(in_w-1920)/2:(in_h-1080)/2%s",
            $zoomSeconds,
            $tail
        );
    }
    $fadeDur = min(0.7, max(0.45, $duration / 6.0));
    $fadeOutStart = max(0.0, $duration - $fadeDur);
    $base = sprintf(',fade=t=in:st=0:d=%.3F,fade=t=out:st=%.3F:d=%.3F', $fadeDur, $fadeOutStart, $fadeDur);
    if ($titleAnimation === 'slide_up') {
        $slideDur = min(0.9, max(0.6, $duration / 5.0));
        return sprintf(
            "%s,pad=1920:1140:0:60:color=black,crop=1920:1080:0:'60*(1-min(1,t/%.3F))'",
            $base,
            $slideDur
        );
    }
    return $base;
}

function buildTitleFilter(string $title, string $subtitle, float $duration, string $transition, string $titleAnimation): string
{
    if (!ffmpegSupportsFilter(DRAWTEXT_FILTER)) {
        return '';
    }

    $filters = [];
    $titleEscaped = ffmpegEscapeText($title);
    $subtitleEscaped = ffmpegEscapeText($subtitle);
    $titleFontOption = titleDrawtextFontOption();
    $alphaExpr = "if(lt(t,0.6),t/0.6,if(gt(t," . max(0.0, $duration - 0.6) . "),(" . max(0.0, $duration) . "-t)/0.6,1))";

    $filters[] = "drawtext=text='{$titleEscaped}':{$titleFontOption}:fontcolor=white:fontsize=78:alpha='{$alphaExpr}':x=(w-text_w)/2:y=(h-text_h)/2-56+30*(1-min(1,t/0.8))";
    if ($subtitleEscaped !== '') {
        $filters[] = "drawtext=text='{$subtitleEscaped}':{$titleFontOption}:fontcolor=white:fontsize=48:alpha='{$alphaExpr}':x=(w-text_w)/2:y=(h-text_h)/2+36+20*(1-min(1,t/0.8))";
    }
    return implode(',', $filters);
}

function buildTitleFilterNoFadeOut(string $title, string $subtitle): string
{
    if (!ffmpegSupportsFilter(DRAWTEXT_FILTER)) {
        return '';
    }

    $filters = [];
    $titleEscaped = ffmpegEscapeText($title);
    $subtitleEscaped = ffmpegEscapeText($subtitle);
    $titleFontOption = titleDrawtextFontOption();
    $alphaExpr = "if(lt(t,0.6),t/0.6,1)";

    $filters[] = "drawtext=text='{$titleEscaped}':{$titleFontOption}:fontcolor=white:fontsize=78:alpha='{$alphaExpr}':x=(w-text_w)/2:y=(h-text_h)/2-56+30*(1-min(1,t/0.8))";
    if ($subtitleEscaped !== '') {
        $filters[] = "drawtext=text='{$subtitleEscaped}':{$titleFontOption}:fontcolor=white:fontsize=48:alpha='{$alphaExpr}':x=(w-text_w)/2:y=(h-text_h)/2+36+20*(1-min(1,t/0.8))";
    }
    return implode(',', $filters);
}

function assEscapeText(string $value): string
{
    $value = str_replace('\\', '\\\\', $value);
    $value = str_replace('{', '\{', $value);
    $value = str_replace('}', '\}', $value);
    return $value;
}

function assTime(float $seconds): string
{
    $seconds = max(0.0, $seconds);
    $h = (int) floor($seconds / 3600);
    $m = (int) floor(($seconds % 3600) / 60);
    $s = $seconds - ($h * 3600) - ($m * 60);
    $secInt = (int) floor($s);
    $centi = (int) round(($s - $secInt) * 100);
    if ($centi >= 100) {
        $secInt += 1;
        $centi = 0;
    }
    return sprintf('%d:%02d:%02d.%02d', $h, $m, $secInt, $centi);
}

function buildAssTitleScript(string $title, string $subtitle, int $duration): string
{
    $titleSafe = assEscapeText($title);
    $subtitleSafe = assEscapeText($subtitle);
    $start = assTime(0.0);
    $end = assTime((float) $duration);

    $script = "[Script Info]\n";
    $script .= "ScriptType: v4.00+\n";
    $script .= "PlayResX: 1920\n";
    $script .= "PlayResY: 1080\n";
    $script .= "\n[V4+ Styles]\n";
    $script .= "Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n";
    $fontName = str_replace(',', '', titleFontFamily());
    $script .= "Style: Title,{$fontName},64,&H00FFFFFF,&H000000FF,&H7F000000,&H64000000,1,0,0,0,100,100,0,0,1,2,0,5,40,40,520,1\n";
    $script .= "Style: Subtitle,{$fontName},42,&H00FFFFFF,&H000000FF,&H7F000000,&H64000000,0,0,0,0,100,100,0,0,1,1,0,5,40,40,420,1\n";
    $script .= "\n[Events]\n";
    $script .= "Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";
    $script .= "Dialogue: 0,{$start},{$end},Title,,0,0,0,,{$titleSafe}\n";
    if ($subtitleSafe !== '') {
        $script .= "Dialogue: 0,{$start},{$end},Subtitle,,0,0,0,,{$subtitleSafe}\n";
    }

    return $script;
}

function makeTitlePart(
    string $outputPath,
    string $title,
    string $subtitle,
    int $duration,
    string $transition,
    string $titleAnimation,
    string $backgroundPath,
    bool $suppressFadeOut = false,
    string $fallbackBlurSourcePath = ''
): void
{
    $args = [
        FFMPEG_BIN,
        '-y',
    ];
    $baseVideoFilter = 'scale=1920:1080:force_original_aspect_ratio=increase,crop=1920:1080';
    if ($backgroundPath !== '' && is_file($backgroundPath)) {
        $args[] = '-loop';
        $args[] = '1';
        $args[] = '-t';
        $args[] = (string) $duration;
        $args[] = '-i';
        $args[] = $backgroundPath;
    } elseif ($fallbackBlurSourcePath !== '' && is_file($fallbackBlurSourcePath)) {
        $ext = strtolower((string) pathinfo($fallbackBlurSourcePath, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $args[] = '-loop';
            $args[] = '1';
            $args[] = '-t';
            $args[] = (string) $duration;
            $args[] = '-i';
            $args[] = $fallbackBlurSourcePath;
        } else {
            $args[] = '-stream_loop';
            $args[] = '-1';
            $args[] = '-t';
            $args[] = (string) $duration;
            $args[] = '-i';
            $args[] = $fallbackBlurSourcePath;
        }
        $baseVideoFilter .= ',boxblur=24:8,eq=brightness=-0.08:saturation=1.08';
    } else {
        $args[] = '-f';
        $args[] = 'lavfi';
        $args[] = '-i';
        $args[] = 'color=c=black:s=1920x1080:r=30:d=' . $duration;
    }

    $baseFilter = titleBaseTransitionFilter((float) $duration, $transition, $titleAnimation, $suppressFadeOut);
    $titleFilter = $suppressFadeOut
        ? buildTitleFilterNoFadeOut($title, $subtitle)
        : buildTitleFilter($title, $subtitle, (float) $duration, $transition, $titleAnimation);
    if ($titleFilter !== '') {
        $args[] = '-vf';
        $args[] = "{$baseVideoFilter}{$baseFilter},{$titleFilter}";
    } elseif (ffmpegSupportsFilter(SUBTITLES_FILTER)) {
        $assPath = $outputPath . '.ass';
        $assScript = buildAssTitleScript($title, $subtitle, $duration);
        if (file_put_contents($assPath, $assScript, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write title subtitles file');
        }
        $subtitlesExpr = 'subtitles=' . $assPath;
        $fontsDir = titleFontsDir();
        if ($fontsDir !== '') {
            $subtitlesExpr .= ':fontsdir=' . $fontsDir;
        }
        $args[] = '-vf';
        $args[] = "{$baseVideoFilter}{$baseFilter},{$subtitlesExpr}";
    } else {
        $args[] = '-vf';
        $args[] = "{$baseVideoFilter}{$baseFilter}";
    }

    $args[] = '-threads';
    $args[] = '1';
    $args[] = '-c:v';
    $args[] = 'libx264';
    $args[] = '-preset';
    $args[] = 'veryfast';
    $args[] = '-pix_fmt';
    $args[] = 'yuv420p';
    $args[] = '-an';
    $args[] = $outputPath;

    runFfmpeg($args);
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

    $transition = sanitizeTransition((string) ($job['transition'] ?? 'cut'));
    $mediaAnimation = sanitizeMediaAnimation((string) ($job['media_animation'] ?? 'none'));
    $titleAnimation = sanitizeTitleAnimation((string) ($job['title_animation'] ?? 'fade'));
    $backgroundPath = resolveBackgroundPath((string) ($job['background'] ?? ''));
    $hasBackground = ($backgroundPath !== '' && is_file($backgroundPath));
    if ($hasBackground && !allowBackgroundMediaAnimation()) {
        // Stable mode: force static media when a background is active.
        $mediaAnimation = 'none';
    }
    $titleDuration = (int) ($job['title_duration'] ?? 4);
    if ($titleDuration < 2 || $titleDuration > 10) {
        $titleDuration = 4;
    }
    $clientFirstName = sanitizeTitleText((string) ($job['client_first_name'] ?? ''), 80);
    $clientLastName = sanitizeTitleText((string) ($job['client_last_name'] ?? ''), 80);
    $tributeName = sanitizeTitleText((string) ($job['tribute_name'] ?? ''), 120);
    $introTitle = sanitizeTitleText((string) ($job['intro_title'] ?? ''), 120);
    $outroTitle = sanitizeTitleText((string) ($job['outro_title'] ?? ''), 120);
    $clientFullName = trim($clientFirstName . ' ' . $clientLastName);

    if ($introTitle === '' && $tributeName !== '') {
        $introTitle = 'Hommage a ' . $tributeName;
    }
    $introSubtitle = $clientFullName !== '' ? 'Famille ' . $clientFullName : '';

    $totalDuration = 0.0;
    $parts = [];

    if ($introTitle !== '') {
        $introBlurSourcePath = '';
        if (!$hasBackground && isset($media[0]) && is_array($media[0])) {
            $introSourceFile = basename((string) ($media[0]['file'] ?? ''));
            if ($introSourceFile !== '') {
                $candidate = $projectDir . '/' . $introSourceFile;
                if (is_file($candidate)) {
                    $introBlurSourcePath = $candidate;
                }
            }
        }
        $introPartPath = $workDir . '/part_intro.mp4';
        makeTitlePart($introPartPath, $introTitle, $introSubtitle, $titleDuration, $transition, $titleAnimation, $backgroundPath, true, $introBlurSourcePath);
        $parts[] = $introPartPath;
        $totalDuration += $titleDuration;
    }

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
            $outputFrames = $duration * 30;
            $itemAnimation = $mediaAnimation;
            if ($itemAnimation === 'random') {
                $choices = $hasBackground
                    ? ['zoom_in', 'zoom_out', 'pan_right', 'pan_left', 'none']
                    : ['slide_premium', 'zoom_in', 'zoom_out', 'pan_right', 'pan_left', 'rotate', 'none'];
                $itemAnimation = $choices[$index % count($choices)];
            }
            // When background + media animation are both active, keep transition as cut
            // to avoid fade/crossfade stalls observed on constrained hosts.
            $imageTransitionMode = ($hasBackground && $itemAnimation !== 'none') ? 'cut' : $transition;
            $imageVisual = imageVisualFilter($transition, $itemAnimation, $duration);
            $isFirstAfterIntro = ($index === 0 && $introTitle !== '');
            $imageTransition = $isFirstAfterIntro
                ? transitionTailOutOnly($imageTransitionMode, (float) $duration)
                : transitionTail($imageTransitionMode, (float) $duration);
            if ($hasBackground) {
                $animationSpec = backgroundImageAnimationSpec($itemAnimation, $duration);
                $imageVisualOnBg = (string) ($animationSpec['fg_filter'] ?? 'scale=1824:1026:force_original_aspect_ratio=decrease');
                $overlayX = (string) ($animationSpec['x'] ?? '(W-w)/2');
                $overlayY = (string) ($animationSpec['y'] ?? '(H-h)/2');
                $enterSec = (float) ($animationSpec['enter_sec'] ?? 0.6);
                $enterSec = max(0.2, min(2.0, $enterSec));
                runFfmpeg([
                    FFMPEG_BIN,
                    '-y',
                    '-loop', '1',
                    '-t', (string) $duration,
                    '-i', $backgroundPath,
                    '-loop', '1',
                    '-t', (string) $duration,
                    '-i', $inputPath,
                    '-filter_complex', "[0:v]scale=1920:1080:force_original_aspect_ratio=increase,crop=1920:1080[bg];[1:v]{$imageVisualOnBg},format=rgba,fade=t=in:st=0:d={$enterSec}:alpha=1[fg];[bg][fg]overlay=x='{$overlayX}':y='{$overlayY}':format=auto:shortest=1{$imageTransition}",
                    '-r', '30',
                    '-frames:v', (string) $outputFrames,
                    '-threads', '1',
                    '-c:v', 'libx264',
                    '-preset', 'veryfast',
                    '-pix_fmt', 'yuv420p',
                    '-an',
                    '-shortest',
                    $partPath,
                ]);
            } else {
                runFfmpeg([
                    FFMPEG_BIN,
                    '-y',
                    '-loop', '1',
                    '-t', (string) $duration,
                    '-i', $inputPath,
                    '-filter_complex', selfBlurBackgroundFilterComplex($imageVisual, '(W-w)/2', '(H-h)/2', $imageTransition),
                    '-r', '30',
                    '-frames:v', (string) $outputFrames,
                    '-threads', '1',
                    '-c:v', 'libx264',
                    '-preset', 'veryfast',
                    '-pix_fmt', 'yuv420p',
                    '-an',
                    $partPath,
                ]);
            }

            $totalDuration += $duration;
            $parts[] = $partPath;
            continue;
        }

        if ($type === 'video') {
            $inputDuration = ffprobeDuration($inputPath);
            $videoVisual = videoVisualFilter($transition, $inputDuration);
            $isFirstAfterIntro = ($index === 0 && $introTitle !== '');
            $videoTransition = $isFirstAfterIntro
                ? transitionTailOutOnly($transition, $inputDuration)
                : transitionTail($transition, $inputDuration);
            if ($hasBackground) {
                $videoVisualOnBg = str_replace(',pad=1920:1080:(ow-iw)/2:(oh-ih)/2', '', $videoVisual);
                runFfmpeg([
                    FFMPEG_BIN,
                    '-y',
                    '-stream_loop', '-1',
                    '-i', $backgroundPath,
                    '-i', $inputPath,
                    '-filter_complex', "[0:v]scale=1920:1080:force_original_aspect_ratio=increase,crop=1920:1080[bg];[1:v]{$videoVisualOnBg}[fg];[bg][fg]overlay=(W-w)/2:(H-h)/2{$videoTransition}",
                    '-map', '1:a?',
                    '-r', '30',
                    '-threads', '1',
                    '-c:v', 'libx264',
                    '-preset', 'veryfast',
                    '-crf', '23',
                    '-c:a', 'aac',
                    '-b:a', '128k',
                    '-shortest',
                    $partPath,
                ]);
            } else {
                runFfmpeg([
                    FFMPEG_BIN,
                    '-y',
                    '-i', $inputPath,
                    '-filter_complex', selfBlurBackgroundFilterComplex($videoVisual, '(W-w)/2', '(H-h)/2', $videoTransition),
                    '-map', '0:a?',
                    '-r', '30',
                    '-threads', '1',
                    '-c:v', 'libx264',
                    '-preset', 'veryfast',
                    '-crf', '23',
                    '-c:a', 'aac',
                    '-b:a', '128k',
                    $partPath,
                ]);
            }

            $totalDuration += ffprobeDuration($partPath);
            $parts[] = $partPath;
            continue;
        }

        throw new RuntimeException('Unsupported media type: ' . $type);
    }

    if ($outroTitle !== '') {
        $outroDuration = $titleDuration + OUTRO_EXTRA_SECONDS;
        $outroBlurSourcePath = '';
        if (!$hasBackground) {
            $lastMedia = $media[count($media) - 1] ?? null;
            if (is_array($lastMedia)) {
                $outroSourceFile = basename((string) ($lastMedia['file'] ?? ''));
                if ($outroSourceFile !== '') {
                    $candidate = $projectDir . '/' . $outroSourceFile;
                    if (is_file($candidate)) {
                        $outroBlurSourcePath = $candidate;
                    }
                }
            }
        }
        $outroPartPath = $workDir . '/part_outro.mp4';
        makeTitlePart($outroPartPath, $outroTitle, '', $outroDuration, $transition, $titleAnimation, $backgroundPath, false, $outroBlurSourcePath);
        $parts[] = $outroPartPath;
        $totalDuration += $outroDuration;
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
        '-r', '30',
        '-threads', '1',
        '-c:v', 'libx264',
        '-preset', 'veryfast',
        '-crf', '22',
        '-pix_fmt', 'yuv420p',
        '-an',
        $mergedPath,
    ]);

    $finalPath = OUTPUTS_DIR . '/' . $projectId . '.mp4';
    $basePath = $workDir . '/base.mp4';
    $music = sanitizeMusicFilename((string) ($job['music'] ?? ''));
    $musicMode = sanitizeMusicMode((string) ($job['music_mode'] ?? 'loop'));
    $logoFile = basename((string) ($job['logo_file'] ?? ''));
    $logoPath = $logoFile !== '' ? ($projectDir . '/' . $logoFile) : '';

    if ($music !== '') {
        $musicPath = MUSIC_DIR . '/' . $music;
        if (!is_file($musicPath)) {
            throw new RuntimeException('Selected music not found: ' . $music);
        }

        if ($musicMode === 'stop') {
            runFfmpeg([
                FFMPEG_BIN,
                '-y',
                '-i', $mergedPath,
                '-i', $musicPath,
                '-map', '0:v:0',
                '-map', '1:a:0',
                '-threads', '1',
                '-c:v', 'copy',
                '-c:a', 'aac',
                $basePath,
            ]);
        } else {
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
                $basePath,
            ]);
        }
    } else {
        if (!rename($mergedPath, $basePath)) {
            throw new RuntimeException('Unable to move final video to outputs');
        }
    }

    if ($logoPath !== '' && is_file($logoPath)) {
        runFfmpeg([
            FFMPEG_BIN,
            '-y',
            '-i', $basePath,
            '-i', $logoPath,
            '-filter_complex', "[1:v]scale='min(260,iw)':-1,format=rgba,colorchannelmixer=aa=0.28[wm];[0:v][wm]overlay=W-w-24:H-h-24:format=auto[v]",
            '-map', '[v]',
            '-map', '0:a?',
            '-threads', '1',
            '-c:v', 'libx264',
            '-preset', 'veryfast',
            '-crf', '22',
            '-c:a', 'copy',
            $finalPath,
        ]);
    } else {
        if (!rename($basePath, $finalPath)) {
            throw new RuntimeException('Unable to move rendered video to outputs');
        }
    }

    recursiveDelete($workDir);

    $job['status'] = 'done';
    $job['updated_at'] = gmdate('c');
    $job['url'] = BASE_URL . 'outputs/' . $projectId . '.mp4';
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
