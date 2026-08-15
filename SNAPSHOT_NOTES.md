# Copie locale de la production

Source : `domains/mcconnery.ca/public_html/video`

Date de copie : 2026-08-15

Cette copie contient uniquement le code et les ressources non sensibles de l'application actuellement en production.

Éléments volontairement exclus :

- `server/config.php`
- `data/users.json`
- les jobs et journaux d'exécution
- les fichiers importés dans `uploads/`
- les vidéos générées dans `outputs/`

Un fichier `server/config.php` propre à l'environnement est nécessaire pour exécuter l'application. Il ne doit jamais être ajouté au dépôt.

## Alerte de sécurité observée

Lors de l'inspection, `https://mcconnery.ca/video/data/users.json` répondait publiquement avec HTTP 200. Son contenu n'a pas été copié ni affiché. Le dossier `data/` doit être protégé avant tout autre test de production.
