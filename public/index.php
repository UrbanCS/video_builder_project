<?php
declare(strict_types=1);

require __DIR__ . '/../server/common.php';
$musicFiles = listMusicFiles();
$postMaxBytes = iniSizeToBytes((string) ini_get('post_max_size'));
$maxTotalUploadBytes = $postMaxBytes > 0 ? (int) floor($postMaxBytes * 0.9) : (38 * 1024 * 1024);
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
    input[type="number"], select {
      width: 100%;
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 10px;
      font-size: 0.95rem;
      background: #fff;
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
    @media (max-width: 760px) {
      .grid { grid-template-columns: 1fr; }
      .preview-item { grid-template-columns: 70px 1fr; }
      .preview-item button { grid-column: 1 / -1; }
    }
  </style>
</head>
<body>
  <main class="container">
    <section class="panel">
      <h1>Générateur de vidéo MP4</h1>
      <p class="muted">Formats autorisés: JPG, PNG, MP4. Max 40 fichiers. Image max: 30 MB. Vidéo max: 150 MB. Durée totale max: 600 sec.</p>

      <div class="dropzone" id="dropzone">
        <label for="mediaInput">Ajouter images/vidéos</label>
        <input id="mediaInput" type="file" accept=".jpg,.jpeg,.png,.mp4" multiple>
        <p class="muted">Glisse-dépose ici ou clique pour sélectionner.</p>
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
          <label for="musicMode">Comportement de la musique</label>
          <select id="musicMode">
            <option value="loop">Boucler jusqu'à la fin de la vidéo</option>
            <option value="stop">Arrêter la musique à la fin de la piste</option>
          </select>
        </div>
      </div>

      <p class="muted">Réordonne les médias par glisser-déposer avant génération.</p>
      <ul id="previewList" class="preview-list"></ul>

      <div style="display:flex; gap:10px; margin-top:12px; align-items:center; flex-wrap:wrap;">
        <button id="generateBtn" type="button">Générer la vidéo</button>
        <span id="countInfo" class="muted">0 fichier</span>
      </div>

      <div id="statusBox" class="status" hidden></div>
      <div id="resultBox" class="result" hidden></div>
    </section>
  </main>

<script>
  const BASE_URL = "<?= BASE_URL ?>";
</script>
<script>
(() => {
  const MAX_FILES = 40;
  const MAX_IMAGE_SIZE = 30 * 1024 * 1024;
  const MAX_VIDEO_SIZE = 150 * 1024 * 1024;
  const MAX_TOTAL_UPLOAD_SIZE = <?= $maxTotalUploadBytes ?>;
  const allowedExt = new Set(['jpg', 'jpeg', 'png', 'mp4']);
  const mediaInput = document.getElementById('mediaInput');
  const dropzone = document.getElementById('dropzone');
  const previewList = document.getElementById('previewList');
  const generateBtn = document.getElementById('generateBtn');
  const imageDurationInput = document.getElementById('imageDuration');
  const musicSelect = document.getElementById('musicSelect');
  const musicMode = document.getElementById('musicMode');
  const statusBox = document.getElementById('statusBox');
  const resultBox = document.getElementById('resultBox');
  const countInfo = document.getElementById('countInfo');

  const mediaState = [];

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
    }
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
    const interval = 2500;
    const maxTries = 360;

    for (let i = 0; i < maxTries; i++) {
      const res = await fetch(`${STATUS_ENDPOINT}?job_id=${encodeURIComponent(jobId)}`, { cache: 'no-store' });
      if (!res.ok) {
        throw new Error('Erreur status endpoint');
      }

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

      await new Promise(r => setTimeout(r, interval));
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

    if (totalUploadSize(mediaState) > MAX_TOTAL_UPLOAD_SIZE) {
      const maxTotalMb = Math.round(MAX_TOTAL_UPLOAD_SIZE / (1024 * 1024));
      setStatus(`Taille totale trop élevée (${maxTotalMb} MB max par envoi).`, true);
      return;
    }

    generateBtn.disabled = true;
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
      formData.append('music', musicSelect.value);
      formData.append('music_mode', musicMode.value);

      const resp = await fetch(GENERATE_ENDPOINT, {
        method: 'POST',
        body: formData
      });

      const raw = await resp.text();
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

  generateBtn.addEventListener('click', submitJob);

  window.addEventListener('DOMContentLoaded', () => {
    Sortable.create(previewList, {
      animation: 120,
      onEnd: () => {
        const order = [...previewList.querySelectorAll('.preview-item')].map(el => el.dataset.id);
        mediaState.sort((a, b) => order.indexOf(a.id) - order.indexOf(b.id));
      }
    });
  });
})();
</script>
</body>
</html>
