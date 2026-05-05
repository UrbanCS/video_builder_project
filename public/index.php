<?php
declare(strict_types=1);

require __DIR__ . '/../server/common.php';
$musicFiles = listMusicFiles();
$postMaxBytes = iniSizeToBytes((string) ini_get('post_max_size'));
$maxTotalUploadBytes = $postMaxBytes > 0 ? (int) floor($postMaxBytes * 0.9) : (38 * 1024 * 1024);
ensureSession();
$currentUser = currentUser();
$currentUserProfile = currentUserProfile($currentUser);
$isOwnerUser = isOwner($currentUser);
$usersConfigured = usersExist();
$flashError = (string) ($_SESSION['flash_error'] ?? '');
$flashSuccess = (string) ($_SESSION['flash_success'] ?? '');
unset($_SESSION['flash_error'], $_SESSION['flash_success']);
$resetToken = trim((string) ($_GET['reset_token'] ?? ''));
$resetType = trim((string) ($_GET['reset_type'] ?? ''));
$isInviteReset = $resetType === 'invite';
$allowPublicSignup = defined('ALLOW_PUBLIC_SIGNUP') && ALLOW_PUBLIC_SIGNUP === true;
$recentJobs = $currentUser !== null ? listJobsForUser($currentUser, 25) : [];
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Video Builder</title>
  <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js" defer></script>
  <style>
    :root {
      --bg: #f6f7fb;
      --card: #ffffff;
      --ink: #121212;
      --muted: #5e6470;
      --accent: #0e8f66;
      --border: #e4e8f0;
      --danger: #b00020;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
      background: radial-gradient(circle at top right, #eef8f4, var(--bg) 45%);
      color: var(--ink);
    }
    .container {
      max-width: 980px;
      margin: 20px auto;
      padding: 16px;
    }
    .panel {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 18px;
      box-shadow: 0 8px 28px rgba(14, 143, 102, 0.08);
    }
    h1 { margin: 0 0 14px; font-size: 1.6rem; }
    .muted { color: var(--muted); font-size: 0.95rem; }
    .grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
      margin: 16px 0;
    }
    label { display: block; font-weight: 600; margin-bottom: 6px; }
    input[type="number"], input[type="text"], select, textarea {
      width: 100%;
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 10px;
      font-size: 0.95rem;
      background: #fff;
    }
    textarea {
      min-height: 86px;
      resize: vertical;
    }
    .dropzone {
      border: 2px dashed #9cc7b8;
      border-radius: 12px;
      padding: 18px;
      text-align: center;
      background: #f7fffc;
      margin-top: 10px;
    }
    .dropzone.dragover { border-color: var(--accent); background: #ebfff7; }
    .preview-list {
      list-style: none;
      margin: 16px 0 0;
      padding: 0;
      display: grid;
      gap: 10px;
    }
    .preview-item {
      display: grid;
      grid-template-columns: 80px 1fr auto;
      gap: 10px;
      align-items: center;
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 10px;
      background: #fff;
      cursor: grab;
    }
    .thumb {
      width: 80px;
      height: 55px;
      border-radius: 8px;
      object-fit: cover;
      border: 1px solid var(--border);
      background: #f1f3f8;
    }
    .tag {
      display: inline-block;
      font-size: 0.75rem;
      padding: 2px 8px;
      border-radius: 999px;
      background: #eaf7f2;
      color: #0a6a4b;
    }
    button {
      border: 0;
      border-radius: 10px;
      background: var(--accent);
      color: white;
      padding: 11px 16px;
      font-size: 0.96rem;
      font-weight: 600;
      cursor: pointer;
    }
    button:disabled { opacity: 0.6; cursor: not-allowed; }
    .danger { color: var(--danger); }
    .status {
      margin-top: 14px;
      padding: 10px;
      border-radius: 10px;
      border: 1px solid var(--border);
      background: #fafcfe;
      font-size: 0.95rem;
      white-space: pre-wrap;
    }
    .result {
      margin-top: 12px;
      padding: 10px;
      border-radius: 10px;
      background: #eafff6;
      border: 1px solid #b9f0d9;
    }
    .progress-wrap {
      margin-top: 12px;
      border: 1px solid var(--border);
      border-radius: 10px;
      background: #fff;
      overflow: hidden;
    }
    .progress-bar {
      height: 10px;
      width: 0%;
      background: linear-gradient(90deg, #0e8f66, #2bb673);
      transition: width 120ms linear;
    }
    .progress-text {
      font-size: 0.9rem;
      color: var(--muted);
      padding: 8px 10px;
    }
    .auth-card {
      max-width: 620px;
      margin: 24px auto;
    }
    .auth-hero {
      max-width: 620px;
      margin: 12px auto 0;
    }
    .auth-hero h1 {
      margin: 0;
      font-size: 2rem;
    }
    .auth-hero p {
      margin: 6px 0 0;
    }
    .topbar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 10px;
      margin-bottom: 12px;
      flex-wrap: wrap;
    }
    .flash-ok {
      margin-top: 10px;
      color: #065f46;
      background: #ecfdf5;
      border: 1px solid #a7f3d0;
      padding: 10px;
      border-radius: 10px;
    }
    .flash-err {
      margin-top: 10px;
      color: #991b1b;
      background: #fef2f2;
      border: 1px solid #fecaca;
      padding: 10px;
      border-radius: 10px;
    }
    .inline-toggle {
      margin-top: 10px;
    }
    .inline-toggle summary {
      cursor: pointer;
      color: var(--accent);
      font-weight: 600;
      user-select: none;
      list-style: none;
    }
    .inline-toggle summary::-webkit-details-marker {
      display: none;
    }
    .inline-toggle[open] summary {
      margin-bottom: 8px;
    }
    .bg-thumb-grid {
      margin-top: 10px;
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(92px, 1fr));
      gap: 8px;
    }
    .bg-thumb {
      border: 2px solid var(--border);
      border-radius: 10px;
      padding: 0;
      background: #fff;
      overflow: hidden;
      cursor: pointer;
      line-height: 0;
    }
    .bg-thumb img {
      width: 100%;
      height: 66px;
      object-fit: cover;
      display: block;
    }
    .bg-thumb.selected {
      border-color: var(--accent);
      box-shadow: 0 0 0 2px rgba(14, 143, 102, 0.18);
    }
    .bg-thumb-label {
      font-size: 0.72rem;
      line-height: 1.2;
      color: var(--muted);
      padding: 4px 6px 6px;
      display: block;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      text-align: left;
    }
    .history-list {
      list-style: none;
      margin: 0;
      padding: 0;
      display: grid;
      gap: 8px;
    }
    .history-item {
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 10px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
      background: #fff;
    }
    .history-meta {
      color: var(--muted);
      font-size: 0.88rem;
    }
    .status-pill {
      display: inline-block;
      font-size: 0.75rem;
      border-radius: 999px;
      padding: 2px 8px;
      border: 1px solid var(--border);
      background: #f8fafc;
    }
    @media (max-width: 760px) {
      .grid { grid-template-columns: 1fr; }
      .preview-item { grid-template-columns: 70px 1fr; }
      .preview-item button { grid-column: 1 / -1; }
    }
  </style>
</head>
<body>
  <main class="container">
    <?php if ($flashSuccess !== ''): ?>
      <div class="flash-ok"><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($flashError !== ''): ?>
      <div class="flash-err"><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($currentUser === null): ?>
      <section class="auth-hero">
        <h1>LifeStories Video</h1>
        <p class="muted">Création de montages hommage</p>
      </section>
      <section class="panel auth-card">
        <h1><?= $usersConfigured ? 'Connexion client' : 'Initialisation du compte propriétaire' ?></h1>
        <?php if ($usersConfigured): ?>
          <?php if ($resetToken !== ''): ?>
            <form method="post" action="../server/auth.php" style="margin-bottom:14px;">
              <input type="hidden" name="action" value="reset_password">
              <input type="hidden" name="reset_token" value="<?= htmlspecialchars($resetToken, ENT_QUOTES, 'UTF-8') ?>">
              <p class="muted" style="margin-top:0;"><?= $isInviteReset ? 'Finalisez votre accès en créant votre mot de passe.' : 'Choisissez un nouveau mot de passe pour votre compte.' ?></p>
              <div class="grid">
                <div>
                  <label for="newPassword"><?= $isInviteReset ? 'Créer votre mot de passe' : 'Nouveau mot de passe' ?></label>
                  <input id="newPassword" name="password" type="password" minlength="8" required>
                </div>
              </div>
              <button type="submit"><?= $isInviteReset ? 'Activer mon compte' : 'Réinitialiser le mot de passe' ?></button>
            </form>
          <?php endif; ?>

          <form method="post" action="../server/auth.php">
            <input type="hidden" name="action" value="login">
            <div class="grid">
              <div>
                <label for="loginEmail">Courriel</label>
                <input id="loginEmail" name="email" type="email" required>
              </div>
              <div>
                <label for="loginPassword">Mot de passe</label>
                <input id="loginPassword" name="password" type="password" minlength="8" required>
              </div>
            </div>
            <button type="submit">Se connecter</button>
          </form>

          <details class="inline-toggle">
            <summary>Mot de passe oublié ?</summary>
            <form method="post" action="../server/auth.php" style="margin-top:8px;">
              <input type="hidden" name="action" value="forgot_password">
              <div class="grid" style="margin-top:0;">
                <div>
                  <label for="forgotEmail">Courriel</label>
                  <input id="forgotEmail" name="email" type="email" required>
                </div>
              </div>
              <button type="submit">Envoyer le lien de réinitialisation</button>
            </form>
          </details>

          <?php if ($allowPublicSignup): ?>
            <form method="post" action="../server/auth.php" style="margin-top:12px;">
              <input type="hidden" name="action" value="register_client">
              <div class="grid" style="margin-top:0;">
                <div>
                  <label for="signupEmail">Créer un compte - courriel</label>
                  <input id="signupEmail" name="email" type="email" required>
                </div>
                <div>
                  <label for="signupPassword">Mot de passe</label>
                  <input id="signupPassword" name="password" type="password" minlength="8" required>
                </div>
              </div>
              <button type="submit">Créer mon compte</button>
            </form>
          <?php endif; ?>
        <?php else: ?>
          <p class="muted">Premier accès: crée le compte propriétaire (owner).</p>
          <form method="post" action="../server/auth.php">
            <input type="hidden" name="action" value="bootstrap_owner">
            <div class="grid">
              <div>
                <label for="ownerEmail">Courriel propriétaire</label>
                <input id="ownerEmail" name="email" type="email" required>
              </div>
              <div>
                <label for="ownerPassword">Mot de passe</label>
                <input id="ownerPassword" name="password" type="password" minlength="8" required>
              </div>
            </div>
            <button type="submit">Créer le compte propriétaire</button>
          </form>
        <?php endif; ?>
      </section>
    <?php else: ?>
    <section class="panel" style="margin-bottom:14px;">
      <div class="topbar">
        <div>
          <strong>Connecté:</strong>
          <?= htmlspecialchars((string) ($currentUser['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
          (<?= htmlspecialchars((string) ($currentUser['role'] ?? 'client'), ENT_QUOTES, 'UTF-8') ?>)
        </div>
        <form method="post" action="../server/auth.php" style="margin:0;">
          <input type="hidden" name="action" value="logout">
          <button type="submit">Déconnexion</button>
        </form>
      </div>

      <?php if (isOwner($currentUser)): ?>
        <h2 style="margin:0 0 10px;">Créer un compte client</h2>
        <form method="post" action="../server/auth.php">
          <input type="hidden" name="action" value="create_client">
          <div class="grid" style="margin-top:0;">
            <div>
              <label for="clientEmail">Courriel client</label>
              <input id="clientEmail" name="email" type="email" required>
            </div>
            <div>
              <label for="clientPassword">Mot de passe initial (optionnel)</label>
              <input id="clientPassword" name="password" type="password" minlength="8">
            </div>
          </div>
          <div class="grid" style="margin-top:0;">
            <div>
              <label for="newClientFirstName">Prénom client</label>
              <input id="newClientFirstName" name="client_first_name" type="text" maxlength="80" placeholder="Ex: Marie">
            </div>
            <div>
              <label for="newClientLastName">Nom client</label>
              <input id="newClientLastName" name="client_last_name" type="text" maxlength="80" placeholder="Ex: Dupont">
            </div>
          </div>
          <div style="margin-top:0;">
            <label for="newClientTributeName">Nom de la personne honorée (verrouillé pour ce client)</label>
            <input id="newClientTributeName" name="tribute_name" type="text" maxlength="120" placeholder="Ex: Jean Dupont">
          </div>
          <p class="muted" style="margin:8px 0 0;">Un courriel d’invitation sera envoyé automatiquement pour définir le mot de passe.</p>
          <button type="submit" style="margin-top:12px;">Créer le compte client</button>
        </form>
      <?php endif; ?>
    </section>

    <section class="panel" style="margin-bottom:14px;">
      <h2 style="margin:0 0 10px; font-size:1.2rem;"><?= $isOwnerUser ? 'Vidéos récentes' : 'Mes vidéos' ?></h2>
      <?php if (count($recentJobs) === 0): ?>
        <p class="muted" style="margin:0;">Aucune vidéo générée pour le moment.</p>
      <?php else: ?>
        <ul class="history-list">
          <?php foreach ($recentJobs as $job): ?>
            <?php
              $createdTs = strtotime((string) ($job['created_at'] ?? ''));
              $createdLabel = $createdTs !== false ? date('Y-m-d H:i', $createdTs) : '-';
              $status = (string) ($job['status'] ?? 'unknown');
            ?>
            <li class="history-item">
              <div>
                <div>
                  <strong><?= htmlspecialchars((string) ($job['project_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                  <span class="status-pill"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="history-meta">
                  Créée: <?= htmlspecialchars($createdLabel, ENT_QUOTES, 'UTF-8') ?>
                  <?php if ($isOwnerUser && (string) ($job['user_email'] ?? '') !== ''): ?>
                    • <?= htmlspecialchars((string) ($job['user_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                  <?php endif; ?>
                  <?php if ($isOwnerUser && (string) ($job['tribute_name'] ?? '') !== ''): ?>
                    • Hommage: <?= htmlspecialchars((string) ($job['tribute_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                  <?php endif; ?>
                </div>
              </div>
              <div>
                <?php if ((string) ($job['url'] ?? '') !== ''): ?>
                  <a href="<?= htmlspecialchars((string) $job['url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Ouvrir</a>
                  &nbsp;|&nbsp;
                  <a href="<?= htmlspecialchars((string) $job['url'], ENT_QUOTES, 'UTF-8') ?>" download>Télécharger</a>
                <?php elseif ($status === 'failed'): ?>
                  <span class="history-meta danger"><?= htmlspecialchars((string) ($job['error'] ?: 'Échec du rendu'), ENT_QUOTES, 'UTF-8') ?></span>
                <?php else: ?>
                  <span class="history-meta">Traitement en cours...</span>
                <?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="panel">
      <h1>Générateur de vidéo MP4</h1>
      <p class="muted">Formats autorisés: JPG, PNG, MP4. Max 40 fichiers. Image max: 30 MB. Vidéo max: 150 MB. Durée totale max: 600 sec. Les grandes photos sont réduites automatiquement à 1920 px de large pour accélérer le rendu.</p>

      <div class="dropzone" id="dropzone">
        <label for="mediaInput">Ajouter images/vidéos</label>
        <input id="mediaInput" type="file" accept=".jpg,.jpeg,.png,.mp4" multiple>
        <p class="muted">Glisse-dépose ici ou clique pour sélectionner.</p>
        <p class="muted" style="margin:8px 0 0;">Le brouillon (fichiers + options) est restauré après reconnexion sur ce même navigateur/appareil.</p>
      </div>

      <div class="grid">
        <div>
          <label for="imageDuration">Durée des images (sec)</label>
          <input id="imageDuration" type="number" min="1" max="10" value="3">
        </div>
        <div>
          <label for="musicSelect">Musique de fond</label>
          <select id="musicSelect">
            <option value="">Aucune musique</option>
            <?php foreach ($musicFiles as $file): ?>
              <option value="<?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="grid" style="margin-top: 0;">
        <div>
          <label for="transitionSelect">Transition</label>
          <select id="transitionSelect">
            <option value="cut">Coupe directe</option>
            <option value="fade">Fondu</option>
            <option value="crossfade">Fondu croisé</option>
            <option value="slide">Glissement</option>
          </select>
          <div style="margin-top: 14px;">
            <label for="titleAnimationSelect">Animation pages titre</label>
            <select id="titleAnimationSelect">
              <option value="fade">Fondu</option>
              <option value="slide_up">Montée douce</option>
              <option value="zoom_in">Zoom léger</option>
              <option value="none">Aucune</option>
            </select>
          </div>
        </div>
        <div>
          <label for="mediaAnimationSelect">Animation images</label>
          <select id="mediaAnimationSelect">
            <option value="none">Aucune</option>
            <option value="zoom_in">Zoom-in</option>
            <option value="zoom_out">Zoom-out</option>
            <option value="pan_right">Déplacement vers la droite</option>
            <option value="pan_left">Déplacement vers la gauche</option>
            <option value="rotate">Rotation légère</option>
            <option value="random">Aléatoire</option>
          </select>
          <p class="muted">Les vidéos utilisent maintenant un fond flou automatique. Les arrière-plans fixes ont été retirés pour garder le rendu stable.</p>
        </div>
      </div>
      <?php if ($isOwnerUser): ?>
      <div class="grid" style="margin-top: 0;">
        <div>
          <label for="logoInput">Logo filigrane (optionnel, PNG/JPG, max 5 MB)</label>
          <input id="logoInput" type="file" accept=".png,.jpg,.jpeg">
        </div>
      </div>
      <?php else: ?>
      <p class="muted" style="margin-top:0;">Mode client: le logo et le nom de la personne honorée sont gérés par l’administrateur.</p>
      <?php endif; ?>
      <div class="grid" style="margin-top: 0;">
        <div>
          <label for="clientFirstName">Prénom client</label>
          <input id="clientFirstName" type="text" maxlength="80" placeholder="Ex: Marie" value="<?= htmlspecialchars((string) ($currentUserProfile['client_first_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" <?= $isOwnerUser ? '' : 'readonly' ?>>
        </div>
        <div>
          <label for="clientLastName">Nom client</label>
          <input id="clientLastName" type="text" maxlength="80" placeholder="Ex: Dupont" value="<?= htmlspecialchars((string) ($currentUserProfile['client_last_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" <?= $isOwnerUser ? '' : 'readonly' ?>>
        </div>
      </div>
      <div class="grid" style="margin-top: 0;">
        <div>
          <label for="tributeName">Nom de la personne honorée</label>
          <input id="tributeName" type="text" maxlength="120" placeholder="Ex: Jean Dupont" value="<?= htmlspecialchars((string) ($currentUserProfile['tribute_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" <?= $isOwnerUser ? '' : 'readonly' ?>>
        </div>
        <div>
          <label for="titleDuration">Durée des pages titre (sec)</label>
          <input id="titleDuration" type="number" min="2" max="10" value="4">
        </div>
      </div>
      <div class="grid" style="margin-top: 0;">
        <div>
          <label for="introTitle">Titre d'ouverture (optionnel)</label>
          <textarea id="introTitle" maxlength="120" placeholder="Ex: Hommage à Jean Dupont"></textarea>
        </div>
        <div>
          <label for="outroTitle">Titre de fin (optionnel)</label>
          <textarea id="outroTitle" maxlength="120" placeholder="Ex: Merci pour votre présence"></textarea>
        </div>
      </div>
      <p class="muted">Réordonne les médias par glisser-déposer avant génération.</p>
      <ul id="previewList" class="preview-list"></ul>

      <div style="display:flex; gap:10px; margin-top:12px; align-items:center; flex-wrap:wrap;">
        <button id="generateBtn" type="button">Générer la vidéo</button>
        <span id="countInfo" class="muted">0 fichier</span>
      </div>

      <div id="uploadProgressWrap" class="progress-wrap" hidden>
        <div id="uploadProgressBar" class="progress-bar"></div>
        <div id="uploadProgressText" class="progress-text">Upload: 0%</div>
      </div>

      <div id="statusBox" class="status" hidden></div>
      <div id="resultBox" class="result" hidden></div>
    </section>
    <?php endif; ?>
  </main>

<script>
  const BASE_URL = "<?= BASE_URL ?>";
  const CURRENT_USER_ID = "<?= htmlspecialchars((string) ($currentUser['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>";
</script>
<?php if ($currentUser !== null): ?>
<script>
(() => {
  const MAX_FILES = 40;
  const MAX_IMAGE_SIZE = 30 * 1024 * 1024;
  const MAX_VIDEO_SIZE = 150 * 1024 * 1024;
  const MAX_LOGO_SIZE = 5 * 1024 * 1024;
  const MAX_TOTAL_UPLOAD_SIZE = <?= $maxTotalUploadBytes ?>;
  const allowedExt = new Set(['jpg', 'jpeg', 'png', 'mp4']);
  const mediaInput = document.getElementById('mediaInput');
  const dropzone = document.getElementById('dropzone');
  const previewList = document.getElementById('previewList');
  const generateBtn = document.getElementById('generateBtn');
  const imageDurationInput = document.getElementById('imageDuration');
  const musicSelect = document.getElementById('musicSelect');
  const transitionSelect = document.getElementById('transitionSelect');
  const mediaAnimationSelect = document.getElementById('mediaAnimationSelect');
  const titleAnimationSelect = document.getElementById('titleAnimationSelect');
  const logoInput = document.getElementById('logoInput');
  const clientFirstNameInput = document.getElementById('clientFirstName');
  const clientLastNameInput = document.getElementById('clientLastName');
  const tributeNameInput = document.getElementById('tributeName');
  const titleDurationInput = document.getElementById('titleDuration');
  const introTitleInput = document.getElementById('introTitle');
  const outroTitleInput = document.getElementById('outroTitle');
  const statusBox = document.getElementById('statusBox');
  const resultBox = document.getElementById('resultBox');
  const countInfo = document.getElementById('countInfo');
  const uploadProgressWrap = document.getElementById('uploadProgressWrap');
  const uploadProgressBar = document.getElementById('uploadProgressBar');
  const uploadProgressText = document.getElementById('uploadProgressText');
  const IS_OWNER = <?= $isOwnerUser ? 'true' : 'false' ?>;
  const DRAFT_KEY = `video_builder_draft_${CURRENT_USER_ID || 'anon'}`;
  const MEDIA_DB_NAME = `video_builder_media_${CURRENT_USER_ID || 'anon'}`;
  const MEDIA_DB_STORE = 'draft';
  const MEDIA_LIST_KEY = 'media_list';

  const mediaState = [];
  let mediaDbPromise = null;
  let mediaPersistQueue = Promise.resolve();

  function supportsMediaDraft() {
    return typeof indexedDB !== 'undefined';
  }

  function openMediaDb() {
    if (!supportsMediaDraft()) {
      return Promise.resolve(null);
    }
    if (mediaDbPromise) {
      return mediaDbPromise;
    }

    mediaDbPromise = new Promise((resolve) => {
      const req = indexedDB.open(MEDIA_DB_NAME, 1);
      req.onupgradeneeded = () => {
        const db = req.result;
        if (!db.objectStoreNames.contains(MEDIA_DB_STORE)) {
          db.createObjectStore(MEDIA_DB_STORE, { keyPath: 'key' });
        }
      };
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => resolve(null);
    });
    return mediaDbPromise;
  }

  async function mediaDbPut(key, value) {
    const db = await openMediaDb();
    if (!db) {
      return false;
    }
    return await new Promise((resolve) => {
      const tx = db.transaction(MEDIA_DB_STORE, 'readwrite');
      tx.objectStore(MEDIA_DB_STORE).put({ key, value });
      tx.oncomplete = () => resolve(true);
      tx.onerror = () => resolve(false);
      tx.onabort = () => resolve(false);
    });
  }

  async function mediaDbGet(key) {
    const db = await openMediaDb();
    if (!db) {
      return null;
    }
    return await new Promise((resolve) => {
      const tx = db.transaction(MEDIA_DB_STORE, 'readonly');
      const req = tx.objectStore(MEDIA_DB_STORE).get(key);
      req.onsuccess = () => resolve(req.result ? req.result.value : null);
      req.onerror = () => resolve(null);
    });
  }

  async function mediaDbDelete(key) {
    const db = await openMediaDb();
    if (!db) {
      return;
    }
    await new Promise((resolve) => {
      const tx = db.transaction(MEDIA_DB_STORE, 'readwrite');
      tx.objectStore(MEDIA_DB_STORE).delete(key);
      tx.oncomplete = () => resolve();
      tx.onerror = () => resolve();
      tx.onabort = () => resolve();
    });
  }

  function mediaFileKey(id) {
    return `file:${id}`;
  }

  function queueMediaPersist(task) {
    mediaPersistQueue = mediaPersistQueue.then(task, task);
    return mediaPersistQueue;
  }

  async function persistMediaList() {
    const compact = mediaState.map((item) => ({
      id: item.id,
      type: item.type,
      name: item.file.name
    }));
    await mediaDbPut(MEDIA_LIST_KEY, compact);
  }

  async function persistMediaItem(item) {
    const fileBlob = item.file instanceof Blob
      ? item.file.slice(0, item.file.size, item.file.type || 'application/octet-stream')
      : new Blob([item.file], { type: 'application/octet-stream' });
    await mediaDbPut(mediaFileKey(item.id), {
      name: item.file.name,
      type: item.file.type || '',
      lastModified: item.file.lastModified || Date.now(),
      mediaType: item.type,
      fileBlob
    });
  }

  async function removeMediaItemDraft(id) {
    await mediaDbDelete(mediaFileKey(id));
    await persistMediaList();
  }

  async function restoreMediaDraft() {
    const list = await mediaDbGet(MEDIA_LIST_KEY);
    if (!Array.isArray(list) || list.length === 0) {
      return;
    }

    mediaState.splice(0, mediaState.length);
    for (const row of list) {
      const id = String(row?.id || '');
      if (!id) {
        continue;
      }
      const payload = await mediaDbGet(mediaFileKey(id));
      if (!payload || !payload.fileBlob) {
        continue;
      }

      let file;
      try {
        file = new File(
          [payload.fileBlob],
          String(payload.name || 'media.bin'),
          {
            type: String(payload.type || ''),
            lastModified: Number(payload.lastModified || Date.now())
          }
        );
      } catch (e) {
        const blob = payload.fileBlob instanceof Blob
          ? payload.fileBlob
          : new Blob([payload.fileBlob], { type: String(payload.type || 'application/octet-stream') });
        file = new File(
          [blob],
          String(payload.name || 'media.bin'),
          {
            type: String(payload.type || ''),
            lastModified: Number(payload.lastModified || Date.now())
          }
        );
      }

      mediaState.push({
        id,
        file,
        type: String(payload.mediaType || row.type || 'image'),
        preview: previewSrc(file)
      });
    }
    render();
    if (mediaState.length > 0) {
      setStatus(`Brouillon restauré: ${mediaState.length} fichier(s)`);
    }
  }

  async function clearMediaDraft() {
    const list = await mediaDbGet(MEDIA_LIST_KEY);
    if (Array.isArray(list)) {
      for (const row of list) {
        const id = String(row?.id || '');
        if (id !== '') {
          await mediaDbDelete(mediaFileKey(id));
        }
      }
    }
    await mediaDbDelete(MEDIA_LIST_KEY);
  }

  function saveSetupDraft() {
    const payload = {
      image_duration: imageDurationInput ? imageDurationInput.value : '',
      music: musicSelect ? musicSelect.value : '',
      transition: transitionSelect ? transitionSelect.value : '',
      media_animation: mediaAnimationSelect ? mediaAnimationSelect.value : '',
      title_animation: titleAnimationSelect ? titleAnimationSelect.value : '',
      title_duration: titleDurationInput ? titleDurationInput.value : '',
      intro_title: introTitleInput ? introTitleInput.value : '',
      outro_title: outroTitleInput ? outroTitleInput.value : '',
      client_first_name: clientFirstNameInput ? clientFirstNameInput.value : '',
      client_last_name: clientLastNameInput ? clientLastNameInput.value : '',
      tribute_name: tributeNameInput ? tributeNameInput.value : ''
    };
    try {
      localStorage.setItem(DRAFT_KEY, JSON.stringify(payload));
    } catch (e) {
      // Storage can fail in private mode/quota limits.
    }
  }

  function restoreSetupDraft() {
    let raw = '';
    try {
      raw = localStorage.getItem(DRAFT_KEY) || '';
    } catch (e) {
      raw = '';
    }
    if (!raw) {
      return;
    }

    let payload = null;
    try {
      payload = JSON.parse(raw);
    } catch (e) {
      return;
    }
    if (!payload || typeof payload !== 'object') {
      return;
    }

    if (imageDurationInput && payload.image_duration !== undefined) {
      imageDurationInput.value = String(payload.image_duration || imageDurationInput.value);
    }
    if (musicSelect && payload.music !== undefined) {
      const value = String(payload.music || '');
      if ([...musicSelect.options].some(opt => opt.value === value)) {
        musicSelect.value = value;
      }
    }
    if (transitionSelect && payload.transition !== undefined) {
      const value = String(payload.transition || '');
      if ([...transitionSelect.options].some(opt => opt.value === value)) {
        transitionSelect.value = value;
      }
    }
    if (mediaAnimationSelect && payload.media_animation !== undefined) {
      const value = String(payload.media_animation || '');
      if ([...mediaAnimationSelect.options].some(opt => opt.value === value)) {
        mediaAnimationSelect.value = value;
      }
    }
    if (titleAnimationSelect && payload.title_animation !== undefined) {
      const value = String(payload.title_animation || '');
      if ([...titleAnimationSelect.options].some(opt => opt.value === value)) {
        titleAnimationSelect.value = value;
      }
    }
    if (titleDurationInput && payload.title_duration !== undefined) {
      titleDurationInput.value = String(payload.title_duration || titleDurationInput.value);
    }
    if (introTitleInput && payload.intro_title !== undefined) {
      introTitleInput.value = String(payload.intro_title || '');
    }
    if (outroTitleInput && payload.outro_title !== undefined) {
      outroTitleInput.value = String(payload.outro_title || '');
    }

    if (IS_OWNER) {
      if (clientFirstNameInput && payload.client_first_name !== undefined) {
        clientFirstNameInput.value = String(payload.client_first_name || '');
      }
      if (clientLastNameInput && payload.client_last_name !== undefined) {
        clientLastNameInput.value = String(payload.client_last_name || '');
      }
      if (tributeNameInput && payload.tribute_name !== undefined) {
        tributeNameInput.value = String(payload.tribute_name || '');
      }
    }
  }

  function uid() {
    return (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : `${Date.now()}_${Math.random().toString(16).slice(2)}`;
  }

  function extFromName(name) {
    const idx = name.lastIndexOf('.');
    return idx === -1 ? '' : name.slice(idx + 1).toLowerCase();
  }

  function setStatus(msg, isError = false) {
    statusBox.hidden = false;
    statusBox.classList.toggle('danger', isError);
    statusBox.textContent = msg;
  }

  function resetStatus() {
    statusBox.hidden = true;
    statusBox.textContent = '';
    statusBox.classList.remove('danger');
    resultBox.hidden = true;
    resultBox.innerHTML = '';
    uploadProgressWrap.hidden = true;
    uploadProgressBar.style.width = '0%';
    uploadProgressText.textContent = 'Upload: 0%';
  }

  function setUploadProgress(percent) {
    const safe = Math.max(0, Math.min(100, Math.round(percent)));
    uploadProgressWrap.hidden = false;
    uploadProgressBar.style.width = `${safe}%`;
    uploadProgressText.textContent = `Upload: ${safe}%`;
  }

  function postWithProgress(url, formData, onProgress) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', url, true);

      xhr.upload.onprogress = (e) => {
        if (!e.lengthComputable) {
          return;
        }
        const percent = (e.loaded / e.total) * 100;
        onProgress(percent);
      };

      xhr.onload = () => {
        resolve({
          ok: xhr.status >= 200 && xhr.status < 300,
          status: xhr.status,
          text: xhr.responseText || ''
        });
      };
      xhr.onerror = () => reject(new Error('Erreur réseau pendant l’upload'));
      xhr.send(formData);
    });
  }

  function refreshCount() {
    countInfo.textContent = `${mediaState.length} fichier${mediaState.length > 1 ? 's' : ''}`;
  }

  function totalUploadSize(files) {
    return files.reduce((sum, item) => sum + item.file.size, 0);
  }

  function removeItem(id) {
    const idx = mediaState.findIndex(item => item.id === id);
    if (idx >= 0) {
      mediaState.splice(idx, 1);
      render();
      queueMediaPersist(async () => {
        await removeMediaItemDraft(id);
      }).catch(() => {});
    }
  }

  function previewSrc(file) {
    if (file.type.startsWith('image/')) {
      return URL.createObjectURL(file);
    }
    return '';
  }

  function addFiles(files) {
    resetStatus();
    for (const file of files) {
      if (mediaState.length >= MAX_FILES) {
        setStatus(`Nombre max atteint (${MAX_FILES}).`, true);
        break;
      }

      const extension = extFromName(file.name);
      if (!allowedExt.has(extension)) {
        setStatus(`Type non autorisé: ${file.name}`, true);
        continue;
      }

      const type = extension === 'mp4' ? 'video' : 'image';
      const maxSize = type === 'image' ? MAX_IMAGE_SIZE : MAX_VIDEO_SIZE;
      const maxSizeMb = Math.round(maxSize / (1024 * 1024));
      if (file.size > maxSize) {
        setStatus(`Fichier trop volumineux (${maxSizeMb} MB max): ${file.name}`, true);
        continue;
      }

      const projectedTotal = totalUploadSize(mediaState) + file.size;
      if (projectedTotal > MAX_TOTAL_UPLOAD_SIZE) {
        const maxTotalMb = Math.round(MAX_TOTAL_UPLOAD_SIZE / (1024 * 1024));
        setStatus(`Taille totale trop élevée (${maxTotalMb} MB max par envoi): ${file.name}`, true);
        continue;
      }

      mediaState.push({
        id: uid(),
        file,
        type,
        preview: previewSrc(file)
      });
      const addedItem = mediaState[mediaState.length - 1];
      queueMediaPersist(async () => {
        await persistMediaItem(addedItem);
      }).catch(() => {});
    }
    queueMediaPersist(async () => {
      await persistMediaList();
    }).catch(() => {});
    render();
  }

  function render() {
    previewList.innerHTML = '';
    mediaState.forEach(item => {
      const li = document.createElement('li');
      li.className = 'preview-item';
      li.dataset.id = item.id;

      const thumb = document.createElement(item.type === 'image' ? 'img' : 'video');
      thumb.className = 'thumb';
      if (item.preview) {
        thumb.src = item.preview;
      }
      if (item.type === 'video') {
        thumb.controls = false;
        thumb.muted = true;
      }

      const info = document.createElement('div');
      const safeName = item.file.name.replace(/[<>]/g, '');
      info.innerHTML = `<strong>${safeName}</strong><br><span class="tag">${item.type.toUpperCase()}</span>`;

      const rmBtn = document.createElement('button');
      rmBtn.type = 'button';
      rmBtn.textContent = 'Supprimer';
      rmBtn.addEventListener('click', () => removeItem(item.id));

      li.appendChild(thumb);
      li.appendChild(info);
      li.appendChild(rmBtn);
      previewList.appendChild(li);
    });

    refreshCount();
  }

  const GENERATE_ENDPOINT = '../server/generate.php';
  const STATUS_ENDPOINT = '../server/status.php';

  async function pollStatus(jobId) {
    const baseInterval = 2500;
    const maxTries = 420;
    let transientErrors = 0;

    for (let i = 0; i < maxTries; i++) {
      const res = await fetch(`${STATUS_ENDPOINT}?job_id=${encodeURIComponent(jobId)}`, { cache: 'no-store' });
      if (!res.ok) {
        if (res.status === 503 || res.status === 429) {
          transientErrors++;
          const waitMs = Math.min(15000, baseInterval * (1 + transientErrors));
          setStatus(`Serveur occupé (${res.status}), nouvelle vérification dans ${Math.ceil(waitMs / 1000)}s...`);
          await new Promise(r => setTimeout(r, waitMs));
          continue;
        }
        throw new Error(`Erreur status endpoint (${res.status})`);
      }
      transientErrors = 0;

      const raw = await res.text();
      let data = null;
      try {
        data = JSON.parse(raw);
      } catch (e) {
        throw new Error(`Réponse status non-JSON: ${raw.slice(0, 180)}`);
      }
      const status = data.status || 'unknown';
      setStatus(`Job ${jobId}: ${status}`);

      if (status === 'done') {
        resultBox.hidden = false;
        const url = data.url || `${BASE_URL}outputs/${jobId}.mp4`;
        resultBox.innerHTML = `Vidéo prête: <a href="${url}" target="_blank" rel="noopener">${url}</a>`;
        return;
      }

      if (status === 'failed') {
        throw new Error(data.error || 'Génération échouée');
      }

      await new Promise(r => setTimeout(r, baseInterval));
    }

    throw new Error('Timeout sur le suivi du job');
  }

  async function submitJob() {
    resetStatus();

    if (mediaState.length === 0) {
      setStatus('Ajoute au moins un fichier.', true);
      return;
    }

    const imageDuration = Number(imageDurationInput.value);
    if (!Number.isFinite(imageDuration) || imageDuration < 1 || imageDuration > 10) {
      setStatus('Durée image invalide (1 à 10).', true);
      return;
    }
    const titleDuration = Number(titleDurationInput.value);
    if (!Number.isFinite(titleDuration) || titleDuration < 2 || titleDuration > 10) {
      setStatus('Durée page titre invalide (2 à 10).', true);
      return;
    }
    if (logoInput && logoInput.files && logoInput.files.length > 0 && logoInput.files[0].size > MAX_LOGO_SIZE) {
      setStatus('Logo trop volumineux (max 5 MB).', true);
      return;
    }
    if (!IS_OWNER && logoInput && logoInput.files && logoInput.files.length > 0) {
      setStatus('Seul un administrateur peut ajouter un logo.', true);
      return;
    }

    if (totalUploadSize(mediaState) > MAX_TOTAL_UPLOAD_SIZE) {
      const maxTotalMb = Math.round(MAX_TOTAL_UPLOAD_SIZE / (1024 * 1024));
      setStatus(`Taille totale trop élevée (${maxTotalMb} MB max par envoi).`, true);
      return;
    }

    generateBtn.disabled = true;
    setUploadProgress(0);
    setStatus('Upload en cours...');

    try {
      const formData = new FormData();
      const order = [];

      mediaState.forEach(item => {
        formData.append(`files[${item.id}]`, item.file, item.file.name);
        order.push(item.id);
      });

      formData.append('order_json', JSON.stringify(order));
      formData.append('image_duration', String(imageDuration));
      formData.append('title_duration', String(titleDuration));
      formData.append('music', musicSelect.value);
      formData.append('transition', transitionSelect.value);
      formData.append('media_animation', mediaAnimationSelect.value);
      formData.append('background', '');
      formData.append('title_animation', titleAnimationSelect.value);
      formData.append('client_first_name', clientFirstNameInput.value || '');
      formData.append('client_last_name', clientLastNameInput.value || '');
      formData.append('tribute_name', tributeNameInput.value || '');
      formData.append('intro_title', introTitleInput.value || '');
      formData.append('outro_title', outroTitleInput.value || '');
      if (IS_OWNER && logoInput && logoInput.files && logoInput.files.length > 0) {
        formData.append('logo', logoInput.files[0], logoInput.files[0].name);
      }

      const resp = await postWithProgress(GENERATE_ENDPOINT, formData, (p) => setUploadProgress(p));
      setUploadProgress(100);
      uploadProgressText.textContent = 'Upload terminé. Traitement en cours...';

      const raw = resp.text;
      let payload = null;
      try {
        payload = JSON.parse(raw);
      } catch (e) {
        throw new Error(`Réponse generate non-JSON: ${raw.slice(0, 180)}`);
      }
      if (!resp.ok || !payload.job_id) {
        throw new Error(payload.error || 'Erreur lors de la création du job');
      }

      setStatus(`Job ${payload.job_id} créé. Attente du worker...`);
      await pollStatus(payload.job_id);
      await mediaPersistQueue.catch(() => {});
      await clearMediaDraft();
    } catch (err) {
      setStatus(String(err.message || err), true);
    } finally {
      generateBtn.disabled = false;
    }
  }

  mediaInput.addEventListener('change', (e) => {
    addFiles([...e.target.files]);
    mediaInput.value = '';
  });

  dropzone.addEventListener('dragover', (e) => {
    e.preventDefault();
    dropzone.classList.add('dragover');
  });

  dropzone.addEventListener('dragleave', () => dropzone.classList.remove('dragover'));

  dropzone.addEventListener('drop', (e) => {
    e.preventDefault();
    dropzone.classList.remove('dragover');
    addFiles([...e.dataTransfer.files]);
  });

  const draftInputs = [
    imageDurationInput,
    musicSelect,
    transitionSelect,
    mediaAnimationSelect,
    titleAnimationSelect,
    titleDurationInput,
    introTitleInput,
    outroTitleInput,
    clientFirstNameInput,
    clientLastNameInput,
    tributeNameInput,
  ];
  draftInputs.forEach((el) => {
    if (el) {
      el.addEventListener('change', saveSetupDraft);
      el.addEventListener('input', saveSetupDraft);
    }
  });

  generateBtn.addEventListener('click', submitJob);

  window.addEventListener('DOMContentLoaded', () => {
    restoreSetupDraft();
    saveSetupDraft();
    Sortable.create(previewList, {
      animation: 120,
      onEnd: () => {
        const order = [...previewList.querySelectorAll('.preview-item')].map(el => el.dataset.id);
        mediaState.sort((a, b) => order.indexOf(a.id) - order.indexOf(b.id));
        queueMediaPersist(async () => {
          await persistMediaList();
        }).catch(() => {});
      }
    });
    restoreMediaDraft().catch(() => {});
  });
})();
</script>
<?php endif; ?>
</body>
</html>
