# Video Builder McConnery

Application web en français pour créer des vidéos hommage à partir de photos et de clips. Les utilisateurs préparent leur montage dans le navigateur; un worker PHP assemble ensuite la vidéo avec FFmpeg et produit un fichier MP4 téléchargeable.

Le projet utilise **PHP, JavaScript et FFmpeg**, sans framework, sans base de données SQL et sans étape de compilation. Les comptes et les tâches de rendu sont stockés dans des fichiers JSON privés.

## Fonctionnalités

- Import de photos JPG/PNG et de vidéos MP4, aperçu et réorganisation par glisser-déposer.
- Durée des photos réglable, transitions et animations de mouvement.
- Fond flouté automatiquement à partir des médias, avec pages d'ouverture et de fin personnalisées.
- Champs « Hommage de la part de » et nom de la personne honorée.
- Musique de fond MP3 et ajout d'un logo en filigrane par le propriétaire.
- Comptes propriétaires (`owner`) et clients, invitations et réinitialisation de mot de passe par courriel.
- Historique filtré selon le compte : un client voit ses créations; un propriétaire voit les siennes et celles de ses clients.
- Sauvegarde du brouillon dans le navigateur via `localStorage` et IndexedDB, selon les capacités et l'espace disponibles.
- Rendu asynchrone et suivi des états `pending`, `processing`, `done` et `failed`.

Le rendu cible le **1920 × 1080, à 30 images/s, en H.264**. La musique sélectionnée est bouclée sur le montage et encodée en AAC. **L'audio original des clips n'est pas conservé dans le fichier final**; sans musique sélectionnée, le montage est silencieux.

## Prérequis

- PHP **8.1 minimum** (le code utilise notamment le type de retour `never`), disponible pour le serveur web et en ligne de commande.
- Sessions, JSON et extension PHP `fileinfo`; GD avec JPEG/PNG pour le redimensionnement des photos. `mbstring` est recommandé pour les textes UTF-8.
- `ffmpeg` et `ffprobe` accessibles dans le `PATH` du processus qui exécute le worker, y compris celui du cron.
- FFmpeg avec l'encodeur `libx264`, l'encodeur AAC et les filtres de composition. Les titres utilisent `drawtext` ou, en repli, `subtitles` avec libass.
- Fonction PHP `exec()` autorisée pour le rendu et accès en écriture aux dossiers `data/`, `jobs/`, `uploads/` et `outputs/`.
- Navigateur récent. Le glisser-déposer utilise SortableJS 1.15.6, chargé depuis jsDelivr.
- Pour les invitations et réinitialisations : envoi de courriels PHP `mail()` configuré chez l'hébergeur.

Aucune commande `npm install` ou `composer install` n'est nécessaire dans cette version.

## Démarrage local

### 1. Récupérer le projet

```sh
git clone https://github.com/UrbanCS/video_builder_project.git
cd video_builder_project
```

### 2. Créer la configuration locale

Créer manuellement `server/config.php` avec cet exemple. Ce fichier est volontairement absent du dépôt et exclu par `.gitignore`.

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

`BASE_URL` est obligatoire et doit se terminer par `/`. Pour une installation dans un sous-dossier, inclure ce chemin, par exemple `https://example.com/video/`. Remplacer `MAIL_FROM` par une adresse autorisée par le service d'envoi avant de tester les courriels.

Les autres constantes sont facultatives : inscriptions publiques désactivées par défaut, police Satisfy et délai de 300 secondes. `TITLE_FONT_FILE` permet d'utiliser explicitement la police fournie. Le délai s'applique à **chaque commande FFmpeg**, lorsque l'utilitaire Linux `timeout` est disponible; il n'est pas appliqué de cette manière sous Windows.

Les noms des exécutables `ffmpeg` et `ffprobe` sont définis dans `server/process_jobs.php`, et non dans cette configuration. Sous Windows, ajouter leur dossier au `PATH` utilisé par PHP.

### 3. Lancer l'interface

Depuis la racine du projet :

```sh
php -S 127.0.0.1:8000 -t .
```

Ouvrir [l'application locale](http://127.0.0.1:8000/). Sur une installation sans comptes, le premier écran permet de créer le propriétaire. Créer ce compte avant d'ouvrir une installation à d'autres utilisateurs.

Le serveur intégré PHP convient uniquement au développement local : il ne prend pas en compte les fichiers `.htaccess`. Garder l'écoute sur `127.0.0.1`.

### 4. Générer une première vidéo

Ajouter quelques photos, choisir une musique et lancer la génération. Dans un second terminal, depuis la même racine :

```sh
php server/process_jobs.php
```

Le worker traite les tâches en attente, puis se termine. Relancer cette commande pour les nouveaux montages ou configurer une tâche planifiée. L'interface interroge le statut et affiche le lien du MP4 lorsque le rendu est terminé.

## Organisation du projet

```text
video_builder_project/
├── index.php                   # Interface, connexion et historique
├── assets/                     # Ressources visuelles de l'interface
├── server/
│   ├── config.php              # À créer localement, jamais versionné
│   ├── common.php              # Comptes, droits, fichiers et fonctions communes
│   ├── auth.php                # Connexion, clients et réinitialisations
│   ├── generate.php            # Réception des médias et création des tâches
│   ├── process_jobs.php        # Worker de rendu FFmpeg
│   ├── status.php              # Statut avec contrôle d'accès
│   └── fonts/                  # Police des titres
├── music/                      # Musiques MP3 proposées dans l'interface
├── data/                       # Comptes et données privées, hors Git
├── jobs/                       # Tâches, verrou et journaux, hors Git
├── uploads/                    # Médias importés et fichiers de travail, hors Git
├── outputs/                    # Vidéos générées, hors Git
├── docs/CPANEL_SETUP.md         # Ancien guide d'hébergement
└── SNAPSHOT_NOTES.md            # Provenance et exclusions de la copie initiale
```

**L'entrée web actuelle est `index.php` à la racine**, avec `server/`, `assets/` et `outputs/` accessibles sous la même URL de base. Les références à `public/index.php` dans l'ancien guide cPanel décrivent une arborescence antérieure.

## Limites actuelles

| Paramètre | Limite applicative |
| --- | --- |
| Médias par montage | 40 |
| Formats importés | JPG, JPEG, PNG, MP4 |
| Taille par photo | 30 Mio |
| Taille par vidéo | 150 Mio |
| Logo JPG/PNG | 5 Mio, réservé au propriétaire |
| Durée d'une photo | 1 à 10 secondes, 3 par défaut |
| Durée des pages titre | 2 à 10 secondes, avec 2 secondes supplémentaires à la fin |
| Durée totale du montage | 600 secondes, titres compris |
| Largeur des photos après optimisation | 1920 pixels maximum, proportions conservées, si GD est disponible |

Ces limites se trouvent principalement dans `server/common.php`; certaines sont aussi reprises dans l'interface. Les limites PHP et celles du serveur web peuvent être plus basses. Ajuster notamment `upload_max_filesize`, `post_max_size`, `max_file_uploads`, `memory_limit` et les délais de réception selon les ressources disponibles. Prévoir un fichier supplémentaire dans `max_file_uploads` lorsqu'un logo accompagne les 40 médias.

## Hébergement et worker planifié

Déployer l'arborescence actuelle dans le dossier web dédié à l'application, créer sa configuration privée et autoriser les écritures nécessaires pour PHP et le worker. Sur un hébergement partagé, conserver les fichiers des autres sites hors du périmètre du déploiement.

Exemple de crontab Linux pour lancer le worker chaque minute, à adapter avec les chemins réels :

```cron
* * * * * /usr/bin/php /chemin/absolu/video_builder_project/server/process_jobs.php >> /chemin/absolu/video_builder_project/jobs/worker.log 2>&1
```

Dans cPanel ou DirectAdmin, saisir la fréquence dans les champs de planification et uniquement la commande dans le champ prévu. Vérifier le `PATH` du cron et la version PHP CLI : ils peuvent différer de ceux de la session terminal ou du site web.

Un verrou `flock` sur `jobs/.process.lock` empêche les workers simultanés. La présence du fichier seule ne signifie pas qu'un worker est actif; ne pas le supprimer pendant un traitement.

## Confidentialité et exploitation

Le dépôt contient le code et les ressources, **pas une sauvegarde complète de l'environnement de production**. La configuration, les comptes, les médias importés, les vidéos, les tâches, les logs et les sauvegardes sont exclus de Git. Les `.gitkeep` et certains `.htaccess` restent versionnés. Un `.gitignore` n'empêche ni l'accès HTTP aux fichiers, ni la publication d'un secret placé dans un fichier déjà suivi.

Avant une mise en ligne, refuser l'accès HTTP à `data/`, `jobs/` et `uploads/`, ainsi qu'aux configurations, sauvegardes et métadonnées Git. Des `.htaccess` sont fournis dans `jobs/` et `uploads/`; leur efficacité dépend de la configuration Apache. **Cette version ne fournit pas de protection `data/.htaccess`** : prévoir une règle serveur équivalente et vérifier son application. Les [notes de copie](SNAPSHOT_NOTES.md) consignent un problème d'accès observé lors de l'import du 15 août 2026; son état actuel n'est pas attesté par ce README.

L'historique et l'endpoint de statut contrôlent les droits des comptes, mais les MP4 sont servis par des liens directs sous `outputs/`. Toute personne disposant d'un tel lien peut potentiellement télécharger la vidéo. Un téléchargement authentifié nécessite un mécanisme supplémentaire si la confidentialité l'exige.

Les brouillons peuvent conserver des médias sur l'appareil utilisé. Après un rendu réussi, le worker nettoie son dossier de travail, mais conserve les médias source et les données du projet; prévoir une politique de sauvegarde privée, de conservation et de nettoyage adaptée.

## Vérifications et dépannage

Vérifier les exécutables et la syntaxe PHP avant déploiement :

```sh
php -v
ffmpeg -version
ffprobe -version
php -l index.php
php -l server/common.php
php -l server/auth.php
php -l server/generate.php
php -l server/process_jobs.php
php -l server/status.php
```

La validation de syntaxe ne remplace pas un essai complet : créer un montage court avec photo, clip et titre, vérifier le téléchargement, puis tester séparément les droits propriétaire/client et les courriels. Aucune suite de tests automatisés n'est fournie dans cette version.

| Symptôme | Vérification |
| --- | --- |
| Erreur au chargement de la page | Présence et syntaxe de `server/config.php`, version PHP, journal d'erreurs privé |
| Tâche bloquée sur `pending` | Planification du worker, chemins PHP/FFmpeg, droits d'écriture et journal du cron |
| Tâche bloquée sur `processing` | État du processus PHP/FFmpeg et journaux; les tâches interrompues ne sont pas automatiquement reprises |
| Rendu en échec | Erreur du job, espace disque, codecs/filtres et durée totale |
| Import refusé ou erreur 413 | Limites PHP, taille totale de la requête et limite du serveur web |
| Titres absents ou mauvaise police | `TITLE_FONT_FILE`, accès à la police et filtres `drawtext`/`subtitles` |
| Invitation ou réinitialisation non reçue | Configuration de `mail()`, expéditeur autorisé, journaux de messagerie et pourriels |
| Lien de téléchargement incorrect | Valeur de `BASE_URL`, slash final et présence du MP4 dans `outputs/` |

Les musiques, logos et polices doivent être utilisés dans le respect de leurs droits respectifs. Aucune licence générale de redistribution n'est définie dans ce dépôt.
