# Video Builder McConnery

A web application with a French-language interface for creating tribute videos from photos and video clips. Users arrange their montage in the browser, then a PHP worker assembles it with FFmpeg and produces a downloadable MP4 file.

The project uses **PHP, JavaScript, and FFmpeg**, with no framework, SQL database, or build step. Accounts and rendering jobs are stored in private JSON files.

## Features

- JPG/PNG photo and MP4 video uploads, previews, and drag-and-drop ordering.
- Adjustable photo duration, transitions, and motion effects.
- Automatic blurred backgrounds derived from the uploaded media, with custom opening and closing title cards.
- A client-editable dedication ("Hommage de la part de"), saved in the browser draft and in each montage; the honored person's name and logo remain owner-managed.
- MP3 background music and an optional logo watermark uploaded by the owner.
- Owner (`owner`) and client accounts, email invitations, and password resets.
- Account-based history: clients see their own creations; owners see their own creations and those of their clients.
- Browser draft storage using `localStorage` and IndexedDB, subject to browser support and available storage.
- Asynchronous rendering with `pending`, `processing`, `done`, and `failed` status tracking.

Output targets **1920 × 1080 at 30 fps, encoded in H.264**. The selected music loops for the duration of the montage and is encoded in AAC. **Original clip audio is not preserved in the final file**; without a selected music track, the montage is silent.

## Requirements

- PHP **8.1 or later** (the code uses the `never` return type), available both through the web server and on the command line.
- Sessions, JSON, and the PHP `fileinfo` extension; GD with JPEG/PNG support for photo resizing. `mbstring` is recommended for UTF-8 text.
- `ffmpeg` and `ffprobe` available in the worker process's `PATH`, including the cron environment.
- FFmpeg with the `libx264` and AAC encoders and the required compositing filters. Titles use `drawtext`, with `subtitles` and libass as a fallback.
- PHP `exec()` enabled for rendering, and write access to `data/`, `jobs/`, `uploads/`, and `outputs/`.
- A recent browser. Drag-and-drop ordering uses SortableJS 1.15.6, loaded from jsDelivr.
- For invitations and password resets: PHP `mail()` delivery configured with the hosting provider.

This version does not require `npm install` or `composer install`.

## Local setup

### 1. Clone the repository

```sh
git clone https://github.com/UrbanCS/video_builder_project.git
cd video_builder_project
```

### 2. Create the local configuration

Manually create `server/config.php` using the example below. This file is intentionally absent from the repository and excluded by `.gitignore`.

```php
<?php
declare(strict_types=1);

const BASE_URL = 'http://127.0.0.1:8000/';
const ALLOW_PUBLIC_SIGNUP = false;
const MAIL_FROM = 'noreply@example.com';
const TITLE_FONT_FAMILY = 'Satisfy';
const TITLE_FONT_FILE = __DIR__ . '/fonts/Satisfy-Regular.ttf';
const FFMPEG_JOB_TIMEOUT_SECONDS = 300;
```

`BASE_URL` is required and must end with `/`. For an installation in a subdirectory, include that path, for example `https://example.com/video/`. Replace `MAIL_FROM` with an address authorized by your email service before testing email delivery.

The other constants are optional: public registration is disabled by default, the default font is Satisfy, and the default timeout is 300 seconds. `TITLE_FONT_FILE` explicitly selects the bundled font. The timeout applies to **each FFmpeg command** when the Linux `timeout` utility is available; it is not enforced this way on Windows.

The `ffmpeg` and `ffprobe` executable names are defined in `server/process_jobs.php`, rather than in this configuration file. On Windows, add their directory to the `PATH` used by PHP.

### 3. Start the web interface

From the project root:

```sh
php -S 127.0.0.1:8000 -t .
```

Open the [local application](http://127.0.0.1:8000/). On an installation with no accounts, the first screen lets you create the owner account. Create this account before making the installation available to other users.

PHP's built-in server is for local development only and does not honor `.htaccess` files. Keep it bound to `127.0.0.1`.

### 4. Render your first video

Add a few photos, select a music track, and submit the montage for rendering. In a second terminal, from the same project root:

```sh
php server/process_jobs.php
```

The worker processes pending jobs, then exits. Run the command again for new montages or configure a scheduled task. The interface polls the job status and displays the MP4 link when rendering finishes.

## Project structure

```text
video_builder_project/
├── index.php                   # Interface, sign-in, and history
├── assets/                     # Interface assets
├── server/
│   ├── config.php              # Created locally, never committed
│   ├── common.php              # Accounts, permissions, files, and shared helpers
│   ├── auth.php                # Sign-in, client accounts, and password resets
│   ├── generate.php            # Media uploads and job creation
│   ├── process_jobs.php        # FFmpeg rendering worker
│   ├── status.php              # Job status with access checks
│   └── fonts/                  # Title font
├── music/                      # MP3 tracks available in the interface
├── data/                       # Accounts and private data, excluded from Git
├── jobs/                       # Jobs, lock, and logs, excluded from Git
├── uploads/                    # Uploaded media and work files, excluded from Git
├── outputs/                    # Generated videos, excluded from Git
├── docs/CPANEL_SETUP.md         # Legacy hosting guide
└── SNAPSHOT_NOTES.md            # Original snapshot source and exclusions
```

**The current web entry point is `index.php` at the project root**, with `server/`, `assets/`, and `outputs/` accessible under the same base URL. References to `public/index.php` in the legacy cPanel guide describe an older directory layout.

## Current limits

| Setting | Application limit |
| --- | --- |
| Media files per montage | 100 |
| Combined media size | 1 GiB per montage |
| Upload formats | JPG, JPEG, PNG, MP4 |
| Photo file size | 30 MiB |
| Video file size | 150 MiB |
| JPG/PNG logo | 5 MiB, owner only |
| Photo duration | 1–10 seconds, default 3 |
| Title card duration | 2–10 seconds, with 2 extra seconds for the closing card |
| Total montage duration | 600 seconds, including title cards |
| Photo width after optimization | Up to 1920 pixels, preserving proportions, when GD is available |

These limits are defined mainly in `server/common.php`; the interface obtains the file-count limit from that constant. PHP and web server limits may be lower. Adjust `upload_max_filesize`, `post_max_size`, `memory_limit`, and request timeouts to accommodate the largest individual file, including multipart overhead.

The interface uploads **one media file per request** through `server/upload.php`, then finalizes the ordered montage through `server/generate.php`; the optional owner logo is sent with finalization. This works even when a hosting restoration resets PHP's `max_file_uploads` to 20 (the regression test uses 1). Upload tokens are account-bound, expire after 24 hours, and cannot create a job until the complete selection is present. Retries of the same file/finalization are idempotent. Expired staging directories are cleaned on the next upload start; completed project media are not affected. Keep `uploads/` inaccessible over HTTP. Pages already open before deployment must be refreshed to use this protocol.

100 photos at 5 seconds each fit within the 600-second limit, including normal title cards. At 6 seconds each, title cards would push the montage over the limit. Video clip duration is checked by the rendering worker.

## Hosting and scheduled rendering

Deploy the current directory structure to the application's dedicated web directory, create its private configuration, and grant the write permissions required by PHP and the worker. On shared hosting, keep other websites' files outside the deployment scope.

Example Linux crontab entry to run the worker every minute; replace the paths with those of your installation:

```cron
* * * * * /usr/bin/php /absolute/path/video_builder_project/server/process_jobs.php >> /absolute/path/video_builder_project/jobs/worker.log 2>&1
```

In cPanel or DirectAdmin, enter the schedule in the scheduling fields and only the command in the command field. Check cron's `PATH` and the PHP CLI version: they may differ from those used by your terminal session or website.

A `flock` lock on `jobs/.process.lock` prevents concurrent workers. The file's presence alone does not mean a worker is active; do not delete it while a job is running.

## Privacy and operations

The repository contains code and assets, **not a complete backup of the production environment**. Configuration, accounts, uploaded media, generated videos, jobs, logs, and backups are excluded from Git. The `.gitkeep` files and selected `.htaccess` files remain tracked. A `.gitignore` does not prevent HTTP access to files or the publication of a secret placed in an already tracked file.

Before going live, deny HTTP access to `data/`, `jobs/`, and `uploads/`, as well as configuration files, backups, and Git metadata. `.htaccess` files are provided in `jobs/` and `uploads/`; their effectiveness depends on the Apache configuration. **This version does not include a protective `data/.htaccess` file**: configure an equivalent server rule and verify that it is enforced. The [snapshot notes](SNAPSHOT_NOTES.md) record an access issue observed during the August 15, 2026 import; this README does not attest to its current status.

The history and status endpoint check account permissions, but MP4 files are served through direct links under `outputs/`. Anyone with such a link may be able to download the video. If privacy requirements call for authenticated downloads, an additional access mechanism is needed.

Drafts may retain media on the user's device. After a successful render, the worker cleans up its working directory but keeps the source media and project data. Establish appropriate private backup, retention, and cleanup policies.

## Validation and troubleshooting

Check the executables and PHP syntax before deployment:

```sh
php -v
ffmpeg -version
ffprobe -version
php -l index.php
php -l server/common.php
php -l server/auth.php
php -l server/generate.php
php -l server/upload.php
php -l server/upload_sessions.php
php -l server/process_jobs.php
php -l server/status.php
```

Run the isolated rendering regression test with PHP CLI and FFmpeg/ffprobe on PATH:

```sh
php tests/render_regression.php < /dev/null
```

On Windows, omit `< /dev/null`. The test creates synthetic media and a temporary configuration outside the application, never reads the production queue, and removes only its own temporary fixture. It checks title cards from photo and video inputs, matching 30 fps/time bases, the expected 72-second montage duration, and the closing card's presence using a frame comparison. Redirecting stdin on Linux prevents FFmpeg launched through `timeout` from stopping on terminal input.

Run `python3 tests/upload_regression.py` with PHP on PATH for an isolated HTTP test. It creates its own private temporary app/configuration/accounts and localhost-only PHP server with `max_file_uploads=1`, then tests 100 files, ordering, finalization, retries, limits, authentication, owner/client permissions, and staging cleanup. It never reads production configuration or accounts.

Syntax and regression checks do not replace an end-to-end test: upload 100 photos through a client account, customize the dedication, reload to verify draft restoration, render, and check the download through the closing card. Separately test email delivery. Existing MP4 files are not automatically regenerated by a code update.

| Symptom | What to check |
| --- | --- |
| Page fails to load | Presence and syntax of `server/config.php`, PHP version, and private error log |
| Job stuck on `pending` | Worker schedule, PHP/FFmpeg paths, write permissions, and cron log |
| Job stuck on `processing` | PHP/FFmpeg process status and logs; interrupted jobs are not automatically resumed |
| Rendering fails | Job error, free disk space, codecs/filters, and total duration |
| Upload rejected or HTTP 413 | PHP limits, total request size, and web server limit |
| Missing titles or incorrect font | `TITLE_FONT_FILE`, font access, and `drawtext`/`subtitles` filters |
| Invitation or reset email not received | `mail()` configuration, authorized sender, mail logs, and spam folder |
| Incorrect download link | `BASE_URL`, trailing slash, and presence of the MP4 in `outputs/` |

Music, logos, and fonts must be used in accordance with their respective rights. This repository does not define a general redistribution license.
