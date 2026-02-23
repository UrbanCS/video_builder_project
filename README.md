# 🎬 Video Builder (PHP + FFmpeg)

A lightweight PHP-based web application that generates MP4 videos from uploaded images and videos, with optional background music.

Designed for deployment on shared cPanel hosting environments with FFmpeg installed.

---

## 🚀 Overview

This project allows users to:

* Upload images (JPG, PNG)
* Upload videos (MP4)
* Reorder media using drag & drop
* Set custom duration for images
* Select royalty-free background music
* Generate a final MP4 video asynchronously

The system is optimized for shared hosting environments and avoids heavy blocking processes by using a queue + cron architecture.

---

## ✨ Features

* Multi-file upload
* Media preview
* Drag & drop ordering (SortableJS)
* Image duration customization
* Background music selection
* FFmpeg-based video rendering
* Asynchronous processing (cron job)
* Secure shell execution
* 1080p output (H.264 + AAC)
* Mobile-compatible video format

---

## 🏗 Architecture

**Frontend**

* HTML
* CSS
* JavaScript
* SortableJS

**Backend**

* PHP 8+
* JSON job queue system
* FFmpeg processing pipeline
* Cron-based background execution

**Output**

* Resolution: 1920x1080
* Frame rate: 30fps
* Video codec: H.264
* Audio codec: AAC
* Pixel format: yuv420p

---

## 📂 Project Structure

```
video_builder_project/
│
├── public/
│   ├── index.php
│
├── server/
│   ├── generate.php
│   ├── process_jobs.php
│   ├── status.php
│
├── uploads/
├── outputs/
├── jobs/
├── music/
```

---

## 🔁 Processing Flow

1. User uploads media
2. Media is validated and renamed (UUID)
3. A job.json file is created with status = pending
4. Cron executes process_jobs.php
5. FFmpeg:

   * Converts images to video clips
   * Normalizes video inputs
   * Concatenates clips
   * Adds background music
6. Final MP4 is saved to `/outputs`
7. Status changes to done

---

## ⚙️ Installation (cPanel)

### 1️⃣ Ensure FFmpeg is installed

```
ffmpeg -version
```

### 2️⃣ Upload project to:

```
public_html/video-builder/
```

### 3️⃣ Set folder permissions:

* uploads/
* outputs/
* jobs/

### 4️⃣ Configure Cron Job (every 1 minute)

```
php /home/USERNAME/public_html/video-builder/server/process_jobs.php
```

---

## 🔐 Security Measures

* File type validation (jpg, png, mp4 only)
* File size limitation
* Max media count limitation
* Escaped shell arguments (escapeshellarg)
* No direct FFmpeg execution from HTTP request
* Background job processing only
* Temporary file cleanup
* Designed for shared hosting resource control

---

## ⚠️ Shared Hosting Notice

This application is optimized for shared hosting environments.

To prevent server overload:

* Video duration limits are enforced
* Media count limits are enforced
* Rendering is done asynchronously
* No blocking FFmpeg calls in HTTP requests

---

## 📦 Output Example

Final output:

```
outputs/{project_id}.mp4
```

Compatible with:

* Mobile
* Instagram
* TikTok
* YouTube

---

## Présentation

Application web en PHP permettant de générer des vidéos MP4 à partir de photos et vidéos, avec ajout optionnel de musique de fond.

Optimisée pour hébergement mutualisé cPanel avec FFmpeg installé.

---

## Fonctionnalités

* Upload multiple
* Réorganisation drag & drop
* Durée personnalisée des images
* Musique libre de droits
* Génération asynchrone via cron
* Export 1080p (H.264 + AAC)

---

## Sécurité

* Validation stricte des fichiers
* Limitation des ressources
* Exécution FFmpeg sécurisée
* Conçue pour serveur partagé

---

# 👨‍💻 Author

Developed for production deployment on shared cPanel hosting.
