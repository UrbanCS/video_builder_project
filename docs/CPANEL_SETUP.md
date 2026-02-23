# Intégration cPanel

## 1) Arborescence

Le projet suppose cette structure:

```text
video_builder_project/
├── public/
│   └── index.php
├── server/
│   ├── common.php
│   ├── generate.php
│   ├── process_jobs.php
│   └── status.php
├── uploads/
├── outputs/
├── jobs/
└── music/
```

## 2) Document Root

Option recommandée:
- Document root du domaine/sous-domaine = `video_builder_project/public`

Si `public` est la racine web, il faut rendre `/server/*.php` accessible via routing interne (ou déplacer les endpoints sous `public/`).

## 3) Cron cPanel (toutes les minutes)

Commande cron recommandée:

```bash
* * * * * /usr/bin/nice -n 10 /usr/local/bin/php /home/USER/video_builder_project/server/process_jobs.php >> /home/USER/video_builder_project/jobs/worker.log 2>&1
```

Adapte:
- `USER`
- chemin PHP (`which php`)
- chemin absolu du projet

## 4) Exemple `job.json`

```json
{
  "project_id": "abc123abc123abcd",
  "status": "pending",
  "created_at": "2026-02-19T16:05:00+00:00",
  "music": "bg_track.mp3",
  "media": [
    {"type": "image", "file": "uuid1.jpg", "duration": 3, "original_name": "slide1.jpg"},
    {"type": "video", "file": "uuid2.mp4", "original_name": "clip.mp4"}
  ]
}
```

## 5) Exemple `list.txt`

```text
file '/home/USER/video_builder_project/uploads/abc123abc123abcd/work/part_000.mp4'
file '/home/USER/video_builder_project/uploads/abc123abc123abcd/work/part_001.mp4'
```

## 6) Sécurité déjà appliquée

- Validation stricte des extensions: `jpg`, `jpeg`, `png`, `mp4`
- Validation image réelle via `getimagesize`
- Limite taille image/vidéo et nombre max de fichiers
- Sélection musique uniquement depuis `music/*.mp3`
- Paramètres shell protégés par `escapeshellarg()`
- Traitement FFmpeg uniquement via worker cron
- Dossiers `jobs/` et `uploads/` protégés par `.htaccess`
- Durée totale max: `600` secondes (contrôlée côté worker)
