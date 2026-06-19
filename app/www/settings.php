<?php require_once __DIR__ . '/includes/db.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SubSyncarr — Settings</title>
<link rel="stylesheet" href="assets/style.css">
<link rel="icon" href="assets/favicon.ico" type="image/png">
</head>
<body>
<script>
(function(){
  var t = localStorage.getItem('subsync-theme') || 'light';
  document.documentElement.setAttribute('data-theme', t);
})();
</script>
<div class="app">

  <div class="header">
    <img src="assets/icon.png" alt="SubSyncarr" style="width:62px;height:62px;border-radius:10px;">
    <div>
      <h1>Sub<font style="font-weight:400">Syncarr -</font> Settings</h1>
      <div class="tagline">Configure your media source and library scraper</div>
    </div>
    <button class="theme-toggle" onclick="toggleTheme()" title="Toggle light/dark mode" style="margin-left:auto" id="themeBtn">☀️</button>
  </div>

  <div class="nav">
    <a href="index.php">Search</a>
    <a href="#" class="active">Settings</a>
  </div>

  <div class="settings-panel">

    <!-- Source Selection -->
    <div class="settings-group">
      <div class="settings-group-title">Media Source</div>
      <div class="radio-group" id="sourceType">
        <label class="radio-option" data-val="kodi">
          <input type="radio" name="source_type" value="kodi"> Kodi
        </label>
        <label class="radio-option" data-val="plex">
          <input type="radio" name="source_type" value="plex"> Plex
        </label>
        <label class="radio-option" data-val="emby">
          <input type="radio" name="source_type" value="emby"> Emby
        </label>
        <label class="radio-option" data-val="jellyfin">
          <input type="radio" name="source_type" value="jellyfin"> Jellyfin
        </label>
      </div>
    </div>

    <!-- Kodi Settings -->
    <div class="settings-group" id="kodiSettings">
      <div class="settings-group-title">Kodi Connection</div>
      <div style="font-size:0.82rem;color:var(--text-dim);margin-bottom:0.85rem;line-height:1.6">
        Works with any Kodi instance — Docker, LibreELEC, Windows, OSMC, etc.<br>
        In Kodi: Settings → Services → Control → enable "Allow remote control via HTTP."
      </div>
      <div class="field">
        <label>Host / IP Address</label>
        <input type="text" id="kodi_host" placeholder="192.168.0.201">
      </div>
      <div class="field">
        <label>Port</label>
        <input type="text" id="kodi_port" placeholder="8080">
        <div style="font-size:0.75rem;color:var(--text-faint);margin-top:0.2rem">
          Default: 8080 (LibreELEC/standalone) or 8018/8019 (Docker). Check Kodi → Settings → Services → Control.
        </div>
      </div>
      <div class="field">
        <label>Username</label>
        <input type="text" id="kodi_user" placeholder="kodi">
      </div>
      <div class="field">
        <label>Password</label>
        <input type="password" id="kodi_pass" placeholder="kodi">
      </div>
      <button class="btn btn-scan" onclick="testConnection()" id="btnTestConnection">Test Connection</button>
      <span id="testResult" style="margin-left:0.75rem;font-size:0.85rem"></span>
    </div>

    <!-- Plex Settings -->
    <div class="settings-group" id="plexSettings" style="display:none">
      <div class="settings-group-title">Plex Connection</div>
      <div style="font-size:0.82rem;color:var(--text-dim);margin-bottom:0.85rem;line-height:1.6">
        Works with any Plex Media Server — Docker, native install, or dedicated hardware.
      </div>
      <div class="field">
        <label>Host / IP Address</label>
        <input type="text" id="plex_host" placeholder="192.168.0.201">
      </div>
      <div class="field">
        <label>Port</label>
        <input type="text" id="plex_port" placeholder="32400">
        <div style="font-size:0.75rem;color:var(--text-faint);margin-top:0.2rem">
          Default: 32400. Check Plex Settings → Remote Access if you changed it.
        </div>
      </div>
      <div class="field">
        <label>Plex Token</label>
        <input type="text" id="plex_token" placeholder="Your X-Plex-Token">
        <div style="font-size:0.75rem;color:var(--text-faint);margin-top:0.3rem;line-height:1.7">
          <strong>How to find your token:</strong><br>
          1. Open <a href="https://app.plex.tv/desktop" target="_blank" style="color:var(--accent)">app.plex.tv/desktop</a> → click any movie → <em>Get Info</em> → <em>View XML</em> → copy the value after <code>X-Plex-Token=</code> in the URL bar<br>
          2. Or from your unRAID terminal:<br>
          <code style="font-size:0.72rem;color:var(--text-dim)">grep -o 'PlexOnlineToken="[^"]*"' "/mnt/user/appdata/PlexMediaServer/Library/Application Support/Plex Media Server/Preferences.xml"</code><br>
          <span style="font-size:0.72rem">Note: the terminal command may return a different token than the web method. Use the web method token first — if Test Connection fails, try the terminal one.</span>
        </div>
      </div>
      <button class="btn btn-scan" onclick="testConnection()" id="btnTestPlex">Test Connection</button>
      <span id="testResultPlex" style="margin-left:0.75rem;font-size:0.85rem"></span>
    </div>

    <!-- Emby Settings -->
    <div class="settings-group" id="embySettings" style="display:none">
      <div class="settings-group-title">Emby Connection</div>
      <div style="font-size:0.82rem;color:var(--text-dim);margin-bottom:0.85rem;line-height:1.6">
        Works with Emby Server — Docker or native install.
      </div>
      <div class="field">
        <label>Host / IP Address</label>
        <input type="text" id="emby_host" placeholder="192.168.0.201">
      </div>
      <div class="field">
        <label>Port</label>
        <input type="text" id="emby_port" placeholder="8096">
        <div style="font-size:0.75rem;color:var(--text-faint);margin-top:0.2rem">Default: 8096</div>
      </div>
      <div class="field">
        <label>API Key</label>
        <input type="text" id="emby_api_key" placeholder="Your Emby API key">
        <div style="font-size:0.75rem;color:var(--text-faint);margin-top:0.3rem;line-height:1.7">
          <strong>How to create an API key:</strong><br>
          Emby Dashboard → Settings → Advanced → API Keys → click the <strong>+</strong> button → name it "SubSyncarr" → copy the generated key.
        </div>
      </div>
      <button class="btn btn-scan" onclick="testConnection()">Test Connection</button>
      <span id="testResultEmby" style="margin-left:0.75rem;font-size:0.85rem"></span>
    </div>

    <!-- Jellyfin Settings -->
    <div class="settings-group" id="jellyfinSettings" style="display:none">
      <div class="settings-group-title">Jellyfin Connection</div>
      <div style="font-size:0.82rem;color:var(--text-dim);margin-bottom:0.85rem;line-height:1.6">
        Works with Jellyfin Media Server — Docker or native install.
      </div>
      <div class="field">
        <label>Host / IP Address</label>
        <input type="text" id="jellyfin_host" placeholder="192.168.0.201">
      </div>
      <div class="field">
        <label>Port</label>
        <input type="text" id="jellyfin_port" placeholder="8096">
        <div style="font-size:0.75rem;color:var(--text-faint);margin-top:0.2rem">Default: 8096</div>
      </div>
      <div class="field">
        <label>API Key</label>
        <input type="text" id="jellyfin_api_key" placeholder="Your Jellyfin API key">
        <div style="font-size:0.75rem;color:var(--text-faint);margin-top:0.3rem;line-height:1.7">
          <strong>How to create an API key:</strong><br>
          Jellyfin Dashboard → Advanced → API Keys → click the <strong>+</strong> button → name it "SubSyncarr" → copy the generated key.
        </div>
      </div>
      <button class="btn btn-scan" onclick="testConnection()">Test Connection</button>
      <span id="testResultJellyfin" style="margin-left:0.75rem;font-size:0.85rem"></span>
    </div>

    <!-- Path Detection Wizard -->
    <div class="settings-group" id="pathSection">
      <div class="settings-group-title">Library Paths</div>
      <div id="pathStatusBox" style="border:1px solid #ffb2b2;padding:12px;border-radius:12px;background-color:#ffe9e9;color:#9f0000;font-size:0.88rem;margin-bottom:1rem;line-height:1.6">
        <strong>⚠ Action Required:</strong> SubSyncarr needs to know where your media server stores files.
        Click the button below to auto-detect your library paths.
      </div>

      <button class="btn btn-sync btn-lg" onclick="detectPaths()">Detect Library Paths</button>
      <span id="detectStatus" style="margin-left:0.75rem;font-size:0.85rem"></span>

      <!-- Detection Results -->
      <div id="pathResults" style="display:none;margin-top:1rem">

        <!-- Movies -->
        <div id="moviePathResult" style="background:var(--surface-alt);border:1px solid var(--border);border-radius:var(--radius-sm);padding:0.85rem;margin-bottom:0.75rem">
          <div style="font-weight:600;font-size:0.85rem;margin-bottom:0.5rem">
            🎬 Movies
          </div>
          <div style="font-size:0.8rem;margin-bottom:0.3rem">
            <span style="color:var(--text-dim)">Source root:</span>
            <code id="movieRootDisplay" style="color:var(--accent)"></code>
          </div>
          <div style="font-size:0.78rem;color:var(--text-faint);margin-bottom:0.3rem">
            Sample: <code id="movieSampleDisplay"></code>
          </div>
          <div style="font-size:0.8rem">
            <span style="color:var(--text-dim)">Maps to container folder:</span>
            <code style="color:var(--success)">/movies</code>
            <span id="movieVerify" style="margin-left:0.5rem;font-size:0.8rem"></span>
          </div>
          <div id="movieCount" style="font-size:0.78rem;color:var(--text-dim);margin-top:0.3rem"></div>
        </div>

        <!-- TV Shows -->
        <div id="tvPathResult" style="background:var(--surface-alt);border:1px solid var(--border);border-radius:var(--radius-sm);padding:0.85rem;margin-bottom:0.75rem">
          <div style="font-weight:600;font-size:0.85rem;margin-bottom:0.5rem">
            📺 TV Shows
          </div>
          <div style="font-size:0.8rem;margin-bottom:0.3rem">
            <span style="color:var(--text-dim)">Source root:</span>
            <code id="tvRootDisplay" style="color:var(--accent)"></code>
          </div>
          <div style="font-size:0.78rem;color:var(--text-faint);margin-bottom:0.3rem">
            Sample: <code id="tvSampleDisplay"></code>
          </div>
          <div style="font-size:0.8rem">
            <span style="color:var(--text-dim)">Maps to container folder:</span>
            <code style="color:var(--success)">/tv</code>
            <span id="tvVerify" style="margin-left:0.5rem;font-size:0.8rem"></span>
          </div>
          <div id="tvCount" style="font-size:0.78rem;color:var(--text-dim);margin-top:0.3rem"></div>
        </div>

        <div style="background:var(--warning-dim);border:1px solid rgba(251,191,36,0.3);border-radius:var(--radius-sm);padding:0.75rem;font-size:0.8rem;line-height:1.6;color:var(--text)">
          <strong>Important:</strong> Your Docker template must map these folders:<br>
          <code>/movies</code> → your unRAID movies folder (e.g. <code>/mnt/user/Media/Movies</code>)<br>
          <code>/tv</code> → your unRAID TV shows folder (e.g. <code>/mnt/user/Media/TV</code>)<br>
          If the paths above show ✓, the mapping is correct.
        </div>
      </div>

      <!-- Manual override (hidden by default) -->
      <div style="margin-top:0.75rem">
        <a href="#" onclick="document.getElementById('manualPaths').style.display='block';this.style.display='none';return false"
           style="font-size:0.78rem;color:var(--text-faint)">Manually override detected paths ▸</a>
        <div id="manualPaths" style="display:none;margin-top:0.5rem">
          <div class="field" id="kodiRootFields">
            <label>Kodi Movie Root</label>
            <input type="text" id="kodi_movie_root" placeholder="smb://192.168.0.201/Media/Movies/">
            <label style="margin-top:0.5rem">Kodi TV Root</label>
            <input type="text" id="kodi_tv_root" placeholder="smb://192.168.0.201/Media/TV/">
          </div>
          <div class="field" id="plexRootFields" style="display:none">
            <label>Plex Movie Root</label>
            <input type="text" id="plex_movie_root" placeholder="/data/Movies/">
            <label style="margin-top:0.5rem">Plex TV Root</label>
            <input type="text" id="plex_tv_root" placeholder="/data/TV Shows/">
          </div>
          <div class="field" id="embyRootFields" style="display:none">
            <label>Emby Movie Root</label>
            <input type="text" id="emby_movie_root" placeholder="/data/Movies/">
            <label style="margin-top:0.5rem">Emby TV Root</label>
            <input type="text" id="emby_tv_root" placeholder="/data/TV Shows/">
          </div>
          <div class="field" id="jellyfinRootFields" style="display:none">
            <label>Jellyfin Movie Root</label>
            <input type="text" id="jellyfin_movie_root" placeholder="/data/Movies/">
            <label style="margin-top:0.5rem">Jellyfin TV Root</label>
            <input type="text" id="jellyfin_tv_root" placeholder="/data/TV Shows/">
          </div>
        </div>
      </div>
    </div>

    <!-- Scrape Settings -->
    <div class="settings-group">
      <div class="settings-group-title">Library Scraping</div>
      <div class="field">
        <label>Auto-scrape Interval</label>
        <select id="scrape_interval">
          <option value="6">Every 6 hours</option>
          <option value="12">Every 12 hours</option>
          <option value="24">Every 24 hours</option>
        </select>
      </div>
      <div style="display:flex;gap:0.5rem;margin-top:0.75rem;flex-wrap:wrap;align-items:center">
        <button class="btn btn-sync btn-lg" onclick="scrapeNow()" id="btnScrape">Scrape Library Now</button>
        <span id="scrapeStatus" style="font-size:0.85rem"></span>
      </div>
    </div>

    <!-- Save -->
    <div style="display:flex;gap:0.75rem;margin-top:1.5rem;padding-top:1rem;border-top:1px solid var(--border);flex-wrap:wrap;align-items:center">
      <button class="btn btn-success btn-lg" onclick="saveSettings()">Save Settings</button>
      <span id="saveStatus" style="font-size:0.85rem"></span>
    </div>

  </div>
</div>

<div class="toast" id="toast"></div>

<script>
const fields = ['kodi_host','kodi_port','kodi_user','kodi_pass','plex_host','plex_port','plex_token','emby_host','emby_port','emby_api_key','jellyfin_host','jellyfin_port','jellyfin_api_key','kodi_movie_root','kodi_tv_root','plex_movie_root','plex_tv_root','emby_movie_root','emby_tv_root','jellyfin_movie_root','jellyfin_tv_root','scrape_interval'];

// ── Load settings ──────────────────────────────────────────────────────
async function loadSettings() {
  try {
    const r = await fetch('api.php?action=get_settings');
    const s = await r.json();
    fields.forEach(f => {
      const el = document.getElementById(f);
      if (el && s[f] !== undefined) el.value = s[f];
    });
    const src = s.source_type || 'kodi';
    setSourceType(src);
    const radio = document.querySelector(`input[name="source_type"][value="${src}"]`);
    if (radio) radio.checked = true;
  } catch(e) { toast('Failed to load settings', 'error'); }
}

function setSourceType(type) {
  document.getElementById('kodiSettings').style.display = type === 'kodi' ? 'block' : 'none';
  document.getElementById('plexSettings').style.display = type === 'plex' ? 'block' : 'none';
  document.getElementById('embySettings').style.display = type === 'emby' ? 'block' : 'none';
  document.getElementById('jellyfinSettings').style.display = type === 'jellyfin' ? 'block' : 'none';
  document.getElementById('kodiRootFields').style.display = type === 'kodi' ? 'block' : 'none';
  document.getElementById('plexRootFields').style.display = type === 'plex' ? 'block' : 'none';
  document.getElementById('embyRootFields').style.display = type === 'emby' ? 'block' : 'none';
  document.getElementById('jellyfinRootFields').style.display = type === 'jellyfin' ? 'block' : 'none';
  document.querySelectorAll('.radio-option').forEach(el => {
    el.classList.toggle('active', el.dataset.val === type);
  });

  // Check if paths are detected for THIS source
  fetch('api.php?action=get_settings').then(r => r.json()).then(s => {
    const movieRoot = s[type + '_movie_root'] || '';
    const tvRoot = s[type + '_tv_root'] || '';
    const sourceName = {kodi:'Kodi', plex:'Plex', emby:'Emby', jellyfin:'Jellyfin'}[type] || type;

    if (movieRoot || tvRoot) {
      setPathBoxGreen(sourceName);
      if (movieRoot) {
        document.getElementById('movieRootDisplay').textContent = movieRoot;
        document.getElementById('movieSampleDisplay').textContent = '(previously detected)';
        verifyMount('/movies', 'movieVerify');
      }
      if (tvRoot) {
        document.getElementById('tvRootDisplay').textContent = tvRoot;
        document.getElementById('tvSampleDisplay').textContent = '(previously detected)';
        verifyMount('/tv', 'tvVerify');
      }
      document.getElementById('pathResults').style.display = 'block';
    } else {
      setPathBoxRed(sourceName);
      document.getElementById('pathResults').style.display = 'none';
    }
  }).catch(() => {});
}
document.querySelectorAll('input[name="source_type"]').forEach(radio => {
  radio.addEventListener('change', () => setSourceType(radio.value));
});

// ── Save ───────────────────────────────────────────────────────────────
async function saveSettings() {
  const data = { setup_complete: '1' };
  fields.forEach(f => { const el = document.getElementById(f); if (el) data[f] = el.value; });
  const srcRadio = document.querySelector('input[name="source_type"]:checked');
  data.source_type = srcRadio ? srcRadio.value : 'kodi';
  try {
    const r = await fetch('api.php?action=save_settings', {
      method: 'POST', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(data)
    });
    const result = await r.json();
    if (result.error) { toast(result.error, 'error'); return; }
    toast('Settings saved', 'success');
    document.getElementById('saveStatus').innerHTML = '<span style="color:var(--success)">✓ Saved</span>';
    setTimeout(() => document.getElementById('saveStatus').textContent = '', 3000);
  } catch(e) { toast('Save failed: ' + e.message, 'error'); }
}

// ── Test Connection ────────────────────────────────────────────────────
async function testConnection() {
  await saveSettings();
  const srcType = document.querySelector('input[name="source_type"]:checked')?.value || 'kodi';
  const resultSpans = {
    kodi: document.getElementById('testResult'),
    plex: document.getElementById('testResultPlex'),
    emby: document.getElementById('testResultEmby'),
    jellyfin: document.getElementById('testResultJellyfin'),
  };
  const result = resultSpans[srcType] || resultSpans.kodi;
  result.innerHTML = '<span class="spinner"></span>';
  try {
    const r = await fetch('api.php?action=test_connection');
    const data = await r.json();
    result.innerHTML = data.ok
      ? '<span style="color:var(--success)">✓ ' + data.message + '</span>'
      : '<span style="color:var(--danger)">✗ ' + data.message + '</span>';
  } catch(e) { result.innerHTML = '<span style="color:var(--danger)">Error: ' + e.message + '</span>'; }
}

// ── Detect Library Paths ───────────────────────────────────────────────
async function detectPaths() {
  await saveSettings();
  const status = document.getElementById('detectStatus');
  const sourceType = document.querySelector('input[name="source_type"]:checked')?.value || 'kodi';
  const sourceName = {kodi:'Kodi', plex:'Plex', emby:'Emby', jellyfin:'Jellyfin'}[sourceType] || sourceType;
  status.innerHTML = `<span class="spinner"></span> Querying ${sourceName} for library paths...`;

  try {
    const r = await fetch('api.php?action=detect_paths');
    const data = await r.json();

    if (data.error) { status.innerHTML = '<span style="color:var(--danger)">✗ ' + data.error + '</span>'; return; }

    const results = document.getElementById('pathResults');

    if (data.movies) {
      document.getElementById('movieRootDisplay').textContent = data.movies.root;
      document.getElementById('movieSampleDisplay').textContent = data.movies.sample_path;
      document.getElementById('movieCount').textContent = (data.movies.section_title || 'Movies') + ': ' + data.movies.count + ' items found';
      // Set the manual override field if it exists
      const movieRootInput = document.getElementById(sourceType + '_movie_root') || document.getElementById('kodi_movie_root');
      if (movieRootInput) movieRootInput.value = data.movies.root;
      verifyMount('/movies', 'movieVerify');
    } else {
      document.getElementById('movieRootDisplay').textContent = 'Not detected';
      document.getElementById('movieVerify').innerHTML = '<span style="color:var(--danger)">✗</span>';
    }

    if (data.tv) {
      document.getElementById('tvRootDisplay').textContent = data.tv.root;
      document.getElementById('tvSampleDisplay').textContent = data.tv.sample_path;
      document.getElementById('tvCount').textContent = (data.tv.section_title || 'TV Shows') + ': ' + data.tv.count + ' items found';
      const tvRootInput = document.getElementById(sourceType + '_tv_root') || document.getElementById('kodi_tv_root');
      if (tvRootInput) tvRootInput.value = data.tv.root;
      verifyMount('/tv', 'tvVerify');
    } else {
      document.getElementById('tvRootDisplay').textContent = 'Not detected';
      document.getElementById('tvVerify').innerHTML = '<span style="color:var(--danger)">✗</span>';
    }

    results.style.display = 'block';
    status.innerHTML = data.ok
      ? '<span style="color:var(--success)">✓ Paths detected</span>'
      : '<span style="color:var(--danger)">✗ Could not detect paths</span>';

    if (data.ok) setPathBoxGreen(sourceName);

    // Auto-save
    await saveSettings();

  } catch(e) { status.innerHTML = '<span style="color:var(--danger)">Error: ' + e.message + '</span>'; }
}

async function verifyMount(containerPath, elId) {
  // Quick check: can the container actually see files at this path?
  try {
    const r = await fetch('api.php?action=scan', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({folder: containerPath})
    });
    const data = await r.json();
    const el = document.getElementById(elId);
    if (data.error) {
      el.innerHTML = '<span style="color:var(--danger)">✗ Not mounted — check Docker volumes</span>';
    } else {
      el.innerHTML = '<span style="color:var(--success)">✓ Mounted</span>';
    }
  } catch(e) {}
}

// ── Scrape Now ─────────────────────────────────────────────────────────
let scrapeInProgress = false;
function beforeUnloadWarning(e) { e.preventDefault(); e.returnValue = ''; }

async function scrapeNow() {
  const status = document.getElementById('scrapeStatus');
  const btn = document.getElementById('btnScrape');
  btn.disabled = true; btn.style.opacity = '0.5'; btn.style.cursor = 'not-allowed';
  scrapeInProgress = true;
  window.addEventListener('beforeunload', beforeUnloadWarning);

  const srcType = document.querySelector('input[name="source_type"]:checked')?.value || 'kodi';
  const srcName = {kodi:'Kodi', plex:'Plex', emby:'Emby', jellyfin:'Jellyfin'}[srcType] || srcType;
  const messages = [
    `Connecting to ${srcName}...`, 'Scraping movies...', 'Scraping TV shows...',
    'Fetching episode details...', 'This can take 2-3 minutes for large libraries...',
    'Still working — fetching episode data for each show...', 'Almost there...',
  ];
  let msgIdx = 0;
  const msgTimer = setInterval(() => {
    if (msgIdx < messages.length) { status.innerHTML = '<span class="spinner"></span> ' + messages[msgIdx]; msgIdx++; }
  }, 15000);
  status.innerHTML = '<span class="spinner"></span> ' + messages[0]; msgIdx = 1;

  try {
    await saveSettings();
    const r = await fetch('api.php?action=scrape');
    const data = await r.json();
    clearInterval(msgTimer);

    if (data.ok) {
      const m = data.movies?.count || 0, s = data.tv?.count || 0, e = data.tv?.episodes || 0;
      const now = new Date();
      const dateStr = now.toLocaleDateString('en-US', {weekday:'long', year:'numeric', month:'long', day:'numeric'});
      const timeStr = now.toLocaleTimeString('en-US', {hour:'numeric', minute:'2-digit', hour12:true});
      status.innerHTML = `<span style="color:var(--success)">✓ ${m} movies, ${s} shows, ${e} episodes — completed ${dateStr} at ${timeStr}</span>`;
      toast('Library scraped successfully', 'success');
    } else {
      const msg = data.movies?.message || data.tv?.message || 'Check connection and paths';
      status.innerHTML = `<span style="color:var(--danger)">✗ ${msg}</span>`;
      toast('Scrape failed', 'error');
    }
  } catch(e) {
    clearInterval(msgTimer);
    status.innerHTML = `<span style="color:var(--danger)">Error: ${e.message}</span>`;
  } finally {
    btn.disabled = false; btn.style.opacity = ''; btn.style.cursor = '';
    scrapeInProgress = false; window.removeEventListener('beforeunload', beforeUnloadWarning);
  }
}

function toast(msg, type) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'toast toast-' + (type || 'info') + ' show';
  setTimeout(() => t.classList.remove('show'), 3500);
}

function toggleTheme() {
  const current = document.documentElement.getAttribute('data-theme') || 'light';
  const next = current === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', next);
  localStorage.setItem('subsync-theme', next);
  document.getElementById('themeBtn').textContent = next === 'dark' ? '☀️' : '🌙';
}

function setPathBoxGreen(sourceName) {
  sourceName = sourceName || 'your media server';
  const box = document.getElementById('pathStatusBox');
  box.style.border = '1px solid #a3e4a3';
  box.style.backgroundColor = '#e9ffe9';
  box.style.color = '#006600';
  box.innerHTML = '<strong>✓ Library paths detected and configured for ' + sourceName + '.</strong> You can re-detect anytime if your sources change.';
}

function setPathBoxRed(sourceName) {
  sourceName = sourceName || 'your media server';
  const box = document.getElementById('pathStatusBox');
  box.style.border = '1px solid #ffb2b2';
  box.style.backgroundColor = '#ffe9e9';
  box.style.color = '#9f0000';
  box.innerHTML = '<strong>⚠ Action Required:</strong> Library paths have not been detected for ' + sourceName + '. Click the button below to auto-detect.';
}
(function(){
  var t = document.documentElement.getAttribute('data-theme') || 'light';
  document.getElementById('themeBtn').textContent = t === 'dark' ? '☀️' : '🌙';
})();

loadSettings();
</script>
</body>
</html>
