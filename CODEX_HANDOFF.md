# Transfert Codex - Video Builder McConnery

Derniere mise a jour : 2026-08-14

## Demarrage obligatoire du prochain chat

Le prochain agent doit commencer par :

1. Lire ce fichier en entier.
2. Travailler dans `C:\xampp\htdocs\video_builder_project` (WSL : `/mnt/c/xampp/htdocs/video_builder_project`).
3. Executer `git status --short --branch` avant toute modification.
4. Lire les fichiers touches avant de les modifier et conserver les changements locaux existants.
5. Distinguer clairement le code local, le depot GitHub et les fichiers actuellement en production.

## Projet

Application PHP de creation de videos hommage MP4 avec FFmpeg. Elle permet notamment :

- l'authentification de proprietaires et de clients;
- l'import d'images et de videos;
- l'ordonnancement des medias;
- les transitions et animations;
- les pages titre d'ouverture et de fin;
- une musique de fond et un logo filigrane;
- le traitement asynchrone par un worker PHP;
- l'historique des videos selon le compte connecte.

Le depot local est `video_builder_project`.

## Hebergement McConnery

- URL publique indiquee par l'utilisateur : `https://mcconnery.ca/video/`
- Acces d'administration indique : `https://mcconnery.ca/cpanel`
- Domaine : `mcconnery.ca`
- Racine apercue dans le gestionnaire de fichiers : `domains/mcconnery.ca/public_html`
- Le site principal dans `public_html` contient deja une installation Joomla (`administrator`, `components`, `modules`, `plugins`, `templates`, etc.). Ne pas ecraser ces dossiers.
- Le dossier public probable de l'application est `domains/mcconnery.ca/public_html/video`, mais cette correspondance doit etre confirmee dans cPanel avant un deploiement. Ne pas presenter cette supposition comme verifiee.
- Les identifiants cPanel, mots de passe, jetons et secrets ne doivent jamais etre ajoutes au depot ni a ce fichier.

Avant tout transfert vers la production, verifier dans cPanel :

- le chemin exact servi par `https://mcconnery.ca/video/`;
- la version PHP active et les extensions requises;
- la presence de `ffmpeg` et `ffprobe` et leurs chemins executables;
- les permissions d'ecriture de `jobs`, `uploads`, `outputs` et `data`;
- la configuration de `server/config.php` sans afficher ses secrets;
- la methode de lancement du worker, normalement une tache cron ou un processus controle;
- les limites PHP d'upload et de temps d'execution.

## Architecture principale

- `public/index.php` : interface, historique, import et suivi des jobs.
- `server/common.php` : fonctions partagees, utilisateurs, droits, jobs et optimisation des images.
- `server/generate.php` : validation des envois et creation des jobs.
- `server/process_jobs.php` : worker FFmpeg et rendu final.
- `server/status.php` : statut d'un job avec controle d'acces.
- `server/auth.php` : connexion, invitation et creation des comptes.
- `server/config.php` : configuration propre a l'environnement; traiter son contenu comme sensible.
- `music/` : chansons disponibles dans l'interface.
- `jobs/` : fichiers JSON et verrou du worker.
- `uploads/` : medias temporaires des projets.
- `outputs/` : videos MP4 generees.
- `data/` : donnees applicatives, dont les comptes selon la configuration actuelle.
- `docs/CPANEL_SETUP.md` : documentation de deploiement existante a relire et mettre a jour si necessaire.

## Etat fonctionnel connu

Les changements suivants ont ete realises dans les echanges precedents. Le prochain agent doit les verifier dans le code et ne pas les refaire aveuglement :

- Les images d'arriere-plan fixes ont ete retirees de l'interface et du flux de generation.
- Le rendu utilise automatiquement une version plein ecran floutee du media comme fond.
- Les pages titre d'ouverture et de fin utilisent respectivement le premier et le dernier media en fond completement flou.
- Les images importees trop larges sont redimensionnees proportionnellement a une largeur maximale de 1920 pixels avant le rendu.
- Le DPI n'est pas force a 72 : pour une video, les dimensions en pixels sont determinantes.
- L'espace vide laisse par le retrait du choix d'arriere-plan a ete corrige dans l'interface.
- Les jobs termines dont le MP4 a ete supprime ne doivent plus apparaitre dans la liste des videos recentes.
- Le champ `Prenom client` / `Nom client` a ete remplace par `Hommage de la part de`.
- Le champ interne `homage_from` est transmis de l'interface au job et affiche dans le sous-titre d'introduction.
- Une compatibilite avec les anciens champs prenom/nom a ete conservee.
- Deux comptes de role `owner` existent. Ne jamais recopier leurs mots de passe dans un fichier de suivi.
- Chaque owner ne doit voir que ses propres creations et celles des clients qu'il a crees.
- `server/status.php` doit appliquer le meme controle d'acces que la liste des videos.

## Regles metier importantes

- Un owner peut creer des comptes clients.
- Un client ne doit voir que ses propres videos.
- Un owner ne doit pas voir automatiquement les travaux d'un autre owner.
- Un owner peut voir les travaux de ses clients rattaches.
- Le nom de la personne honoree peut etre verrouille pour un client.
- Le logo du salon doit etre gere par l'owner et applique aux videos concernees.
- Les controles importants doivent etre appliques cote serveur, pas seulement dans l'interface.

## Worker FFmpeg

Le worker est `server/process_jobs.php`. En production, eviter de lancer plusieurs workers concurrents. Le fichier `jobs/.process.lock` est un verrou d'execution et ne doit pas etre ajoute a Git.

Exemples de diagnostic a adapter au chemin cPanel reel :

```sh
ps aux | grep "server/process_jobs.php" | grep -v grep
ps aux | grep -E "process_jobs.php|ffmpeg" | grep -v grep
tail -n 120 jobs/worker.log
```

Ne pas supprimer un verrou sans avoir d'abord confirme qu'aucun worker actif ne le tient.

## Deploiement prudent

Le site Joomla principal partage le meme `public_html`. Un deploiement doit donc rester limite au dossier de l'application video et aux dossiers prives qui lui appartiennent. Ne jamais remplacer globalement `public_html`.

Sequence recommandee :

1. Sauvegarder les fichiers live et les donnees de l'application.
2. Comparer le commit local avec la version actuellement en production.
3. Verifier le chemin reel de `/video/` dans cPanel.
4. Televerser seulement les fichiers modifies de l'application.
5. Conserver la configuration et les donnees live sensibles.
6. Verifier la syntaxe PHP sur le serveur si possible.
7. Relancer ou laisser cron relancer le worker selon la configuration reelle.
8. Tester une connexion owner, une connexion client, l'isolation des historiques, un upload photo, un rendu court et le telechargement final.

## Points restant a confirmer

- Le mapping exact entre `https://mcconnery.ca/video/` et le dossier cPanel n'a pas ete reverifie dans ce chat.
- L'etat actuel du depot Git, de la branche distante et de la production doit etre controle avant tout nouveau changement.
- La configuration cron du worker sur McConnery doit etre confirmee.
- Les chemins reels de `ffmpeg` et `ffprobe` sur ce compte d'hebergement doivent etre confirmes.
- L'envoi des invitations et reinitialisations par courriel doit etre teste en production avant d'etre annonce comme fonctionnel.

## Consigne pour les reponses au client

Communiquer en francais, de facon courte et concrete. Ne jamais annoncer qu'un deploiement, un courriel ou un rendu fonctionne en production sans l'avoir verifie. Pour les messages destines a Antoni, rediger le texte seulement, sauf demande explicite de l'envoyer avec un connecteur disponible.
