<?php
require_once __DIR__ . '/includes/db.php';
$setupDone = DB::getSetting('setup_complete', '0') === '1';
if (!$setupDone) { header('Location: settings.php'); exit; }
$pathsDone = DB::getSetting('paths_detected', '0') === '1';
$lastScrape = DB::getSetting('last_scrape', '');
$needsSetup = !$pathsDone || !$lastScrape;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SubSyncarr</title>
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

  <!-- Header -->
  <div class="header">
    <img src="assets/icon.png" alt="SubSyncarr" style="width:62px;height:62px;border-radius:10px;">
    <div>
      <h1>Sub<font style="font-weight:400">Syncarr</font></h1>
      <div class="tagline">Fix out-of-sync subtitles for your media library</div>
    </div>
    <div style="margin-left:auto;display:flex;align-items:center;gap:0.75rem">
      <a href="https://www.buymeacoffee.com/hernandito" target="_blank" rel="noopener" title="Support SubSyncarr — buy me a coffee" class="bmc-btn">
        <img src="assets/bmc.png" alt="Buy Me a Coffee" class="bmc-img">
      </a>
      <button class="theme-toggle" onclick="toggleTheme()" title="Toggle light/dark mode" id="themeBtn">☀️</button>
    </div>
  </div>

  <!-- Navigation  -->
  <div class="nav">
    <a href="#" class="active" data-page="search">Search</a>
    <a href="#" data-page="queue">Queue<span id="queueBadge"></span></a>
    <a href="settings.php">Settings</a>
  </div>

  <!-- Stats bar -->
  <div class="stats-bar" id="statsBar">
    <div class="stat"><div class="stat-value" id="statMovies">-</div><div class="stat-label">Movies</div></div>
    <div class="stat"><div class="stat-value" id="statShows">-</div><div class="stat-label">TV Shows</div></div>
    <div class="stat"><div class="stat-value" id="statEpisodes">-</div><div class="stat-label">Episodes</div></div>
    <div class="stat"><div class="stat-value" id="statSyncs">-</div><div class="stat-label">Synced</div></div>
    <div class="stat"><div class="stat-value" id="statLastScrape">-</div><div class="stat-label">Last Scrape</div></div>
  </div>

  <!-- Setup Warning Banner -->
  <?php if ($needsSetup): ?>
  <div style="background:var(--warning-dim);border:1px solid rgba(251,191,36,0.3);border-radius:var(--radius);padding:1rem;margin-bottom:1.25rem;font-size:0.9rem;line-height:1.6">
    <strong style="color:var(--warning)">⚠ Setup Required</strong><br>
    <?php if (!$pathsDone): ?>
      <span style="color:var(--text)">Library paths have not been detected.</span>
      <a href="settings.php" style="color:var(--accent)">Go to Settings</a> → click <strong>Detect Library Paths</strong> to configure.
    <?php elseif (!$lastScrape): ?>
      <span style="color:var(--text)">Library has not been scraped yet.</span>
      <a href="settings.php" style="color:var(--accent)">Go to Settings</a> → click <strong>Scrape Library Now</strong> to populate your movie and TV show database.
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Search Page -->
  <div id="pageSearch">
    <div class="search-wrap">
      <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="text" id="searchInput" class="search-input" placeholder="Search movies and TV shows..." autocomplete="off">
      <button class="search-clear" id="searchClear" onclick="clearSearch()" title="Clear">✕</button>
      <div class="results-dropdown" id="resultsDropdown"></div>
    </div>

    <!-- Selected item panel -->
    <div class="selected-panel" id="selectedPanel">
      <div class="selected-header">
        <img id="selPoster" class="selected-poster" src="" alt="">
        <div class="selected-info">
          <div class="selected-title" id="selTitle"></div>
          <div class="selected-meta" id="selMeta"></div>
          <div class="selected-path" id="selPath"></div>
          <div style="margin-top:0.75rem" id="selActions"></div>
        </div>
      </div>
      <!-- TV Episodes tree goes here -->
      <div id="tvEpisodes" style="display:none"></div>
    </div>

    <!-- Scan results panel -->
    <div class="scan-panel" id="scanPanel">
      <div class="panel-title">
        <span>Subtitle Files</span>
        <span id="scanSummary" style="font-weight:400;font-size:0.8rem;color:var(--text-dim)"></span>
      </div>
      <div id="scanResults"></div>
      <div id="findSubsBtn" style="display:none;margin-top:1rem;display:none;align-items:center;gap:0.75rem;flex-wrap:wrap">
        <button class="btn btn-sync btn-lg" id="findAllBtn" onclick="findSubtitles()">Find Subtitles Online</button>
        <select id="subSearchLang" style="padding:0.45rem 0.6rem;border-radius:var(--radius-sm);border:1px solid var(--border);background:var(--surface);color:var(--text);font-size:0.85rem">
          <option value="en">English</option>
          <option value="es">Spanish</option>
          <option value="fr">French</option>
          <option value="de">German</option>
          <option value="it">Italian</option>
          <option value="pt">Portuguese</option>
          <option value="ru">Russian</option>
          <option value="zh">Chinese</option>
          <option value="ja">Japanese</option>
          <option value="ko">Korean</option>
          <option value="ar">Arabic</option>
          <option value="nl">Dutch</option>
          <option value="pl">Polish</option>
          <option value="sv">Swedish</option>
          <option value="tr">Turkish</option>
          <option value="da">Danish</option>
          <option value="fi">Finnish</option>
          <option value="no">Norwegian</option>
          <option value="cs">Czech</option>
          <option value="hu">Hungarian</option>
          <option value="ro">Romanian</option>
          <option value="he">Hebrew</option>
        </select>
      </div>
    </div>

    <!-- Subtitle search results panel -->
    <div class="sub-search-panel" id="subSearchPanel">
      <div class="panel-title">
        <span>Subtitles Found Online</span>
        <span id="subSearchSummary" style="font-weight:400;font-size:0.8rem;color:var(--text-dim)"></span>
      </div>
      <div id="subSearchContext" class="sub-search-context" style="display:none"></div>
      <div id="subSearchResults"></div>
    </div>
  </div>

  <!-- Queue Page -->
  <div id="pageQueue" style="display:none">
    <div class="queue-panel">
      <div class="panel-title">
        <span>Sync Queue</span>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap">
          <button class="btn btn-scan" onclick="clearSelected()">Clear Selected</button>
          <button class="btn btn-scan" onclick="clearFailed()">Clear Failed</button>
          <button class="btn btn-scan" onclick="clearQueue()">Clear All</button>
        </div>
      </div>
      <div id="queueList">
        <div class="queue-empty">
          <div class="queue-empty-icon">🎬</div>
          No sync jobs yet.<br>Search for a movie or TV show, scan its folder, and click Sync.
        </div>
      </div>
    </div>
  </div>

</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<script>
// ── State ──────────────────────────────────────────────────────────────
let searchTimer = null;
let queueTimer = null;
let currentItem = null;
let searchResults = []; // Store results to avoid onclick quote issues

// ── Navigation ─────────────────────────────────────────────────────────
document.querySelectorAll('.nav a[data-page]').forEach(link => {
  link.addEventListener('click', e => {
    e.preventDefault();
    const page = link.dataset.page;
    document.querySelectorAll('.nav a[data-page]').forEach(l => l.classList.remove('active'));
    link.classList.add('active');
    document.getElementById('pageSearch').style.display = page === 'search' ? 'block' : 'none';
    document.getElementById('pageQueue').style.display = page === 'queue' ? 'block' : 'none';
    if (page === 'queue') refreshQueue();
  });
});

// ── Stats ──────────────────────────────────────────────────────────────
async function loadStats() {
  try {
    const r = await fetch('api.php?action=stats');
    const s = await r.json();
    const fmt = n => Number(n || 0).toLocaleString('en-US');
    document.getElementById('statMovies').textContent = fmt(s.movies);
    document.getElementById('statShows').textContent = fmt(s.tv_shows);
    document.getElementById('statEpisodes').textContent = fmt(s.tv_episodes);
    document.getElementById('statSyncs').textContent = fmt(s.syncs_done);
    const ls = s.last_scrape || 'Never';
    if (ls === 'Never') {
      document.getElementById('statLastScrape').textContent = ls;
    } else {
      // Locale-aware short date + time. Browser picks 6/23/26 10:45 AM (US)
      // or 23/06/26 10:45 (EU) or 2026-06-23 10:45 (ISO regions) automatically.
      const d = new Date(ls.replace(' ', 'T'));
      const dateStr = new Intl.DateTimeFormat(undefined, {
        year: '2-digit', month: 'numeric', day: 'numeric'
      }).format(d);
      const timeStr = new Intl.DateTimeFormat(undefined, {
        hour: 'numeric', minute: '2-digit'
      }).format(d);
      document.getElementById('statLastScrape').textContent = `${dateStr} ${timeStr}`;
    }
  } catch(e) {}
}
loadStats();

// ── Search ─────────────────────────────────────────────────────────────
const searchInput = document.getElementById('searchInput');
const dropdown = document.getElementById('resultsDropdown');

searchInput.addEventListener('input', () => {
  clearTimeout(searchTimer);
  const q = searchInput.value.trim();
  // Show/hide clear button
  document.getElementById('searchClear').style.display = q.length > 0 ? 'block' : 'none';
  if (q.length < 2) { dropdown.style.display = 'none'; return; }
  searchTimer = setTimeout(() => doSearch(q), 200);
});

searchInput.addEventListener('blur', () => {
  setTimeout(() => dropdown.style.display = 'none', 250);
});

function clearSearch() {
  searchInput.value = '';
  dropdown.style.display = 'none';
  document.getElementById('searchClear').style.display = 'none';
  document.getElementById('selectedPanel').style.display = 'none';
  document.getElementById('scanPanel').style.display = 'none';
  document.getElementById('subSearchPanel').style.display = 'none';
  currentItem = null;
  searchResults = [];
  searchInput.focus();
}

async function doSearch(q) {
  try {
    const r = await fetch('api.php?action=search&q=' + encodeURIComponent(q));
    const items = await r.json();
    if (!items.length) {
      dropdown.innerHTML = '<div class="result-item"><div class="result-info"><div class="result-title" style="color:var(--text-dim)">No results found</div></div></div>';
      dropdown.style.display = 'block';
      return;
    }

    searchResults = items; // Store for safe onclick reference

    dropdown.innerHTML = items.map((item, idx) => {
      const poster = item.poster_url
        ? `<img class="result-poster" src="${esc(item.poster_url)}" loading="lazy" onerror="this.style.display='none'">`
        : `<div class="result-poster-placeholder">?</div>`;
      const badge = item.type === 'movie'
        ? '<span class="result-badge badge-movie">Movie</span>'
        : '<span class="result-badge badge-tv">TV</span>';
      const meta = item.type === 'movie'
        ? [item.year, item.rating ? '★ ' + item.rating : '', item.genre].filter(Boolean).join(' · ')
        : [item.year, item.seasons + ' seasons', item.episode_count + ' eps'].filter(Boolean).join(' · ');
      const plot = item.plot ? `<div class="result-plot">${esc(item.plot)}</div>` : '';
      return `<div class="result-item" onclick="selectItem(searchResults[${idx}])">
        ${poster}
        <div class="result-info">
          <div class="result-title">${esc(item.title)}</div>
          <div class="result-meta">${meta}</div>
          ${plot}
        </div>
        ${badge}
      </div>`;
    }).join('');
    dropdown.style.display = 'block';
  } catch(e) {
    dropdown.innerHTML = '<div class="result-item"><div class="result-info"><div class="result-title" style="color:var(--danger)">Search error</div></div></div>';
    dropdown.style.display = 'block';
  }
}

// ── Item Selection ─────────────────────────────────────────────────────
async function selectItem(item) {
  currentItem = item;
  searchInput.value = '';
  document.getElementById('searchClear').style.display = 'none';
  dropdown.style.display = 'none';

  const panel = document.getElementById('selectedPanel');
  const poster = document.getElementById('selPoster');
  const tvEps = document.getElementById('tvEpisodes');

  // Reset all panels
  document.getElementById('scanPanel').style.display = 'none';
  document.getElementById('subSearchPanel').style.display = 'none';
  tvEps.style.display = 'none';
  tvEps.innerHTML = '';

  document.getElementById('selTitle').textContent = item.title + (item.year ? ` (${item.year})` : '');
  poster.src = item.poster_url || '';
  poster.style.display = item.poster_url ? 'block' : 'none';

  if (item.type === 'movie') {
    const rating = item.rating ? '★ ' + item.rating : '';
    const year = item.year || '';
    document.getElementById('selMeta').textContent = [year, rating].filter(Boolean).join(' · ');

    // Genre line
    let genreHtml = '';
    if (item.genre) {
      genreHtml = `<div class="selected-genre">${esc(item.genre)}</div>`;
    }

    // IMDB link
    let imdbHtml = '';
    if (item.imdb_id) {
      imdbHtml = `<div class="selected-imdb"><a href="https://www.imdb.com/title/${esc(item.imdb_id)}/" target="_blank">View on IMDB ↗</a></div>`;
    }

    // Plot
    let plotHtml = '';
    if (item.plot) {
      plotHtml = `<div style="font-size:0.85rem;color:var(--text-dim);line-height:1.6;margin-bottom:0.75rem;max-height:120px;overflow-y:auto">${esc(item.plot)}</div>`;
    }

    document.getElementById('selPath').innerHTML = genreHtml + plotHtml + imdbHtml +
      `<div style="margin-top:0.5rem;font-size:0.75rem;color:var(--text-faint);word-break:break-all">${esc(item.folder_path || 'No path available')}</div>`;

    document.getElementById('selActions').innerHTML = item.folder_path
      ? `<button class="btn btn-sync btn-lg" onclick="scanFolder('${escJs(item.folder_path)}')">Scan for Subtitles</button>`
      : '<span style="color:var(--warning);font-size:0.85rem">No folder path — run a library scrape first</span>';
    tvEps.style.display = 'none';

  } else if (item.type === 'tv') {
    document.getElementById('selMeta').textContent = `${item.seasons || '?'} seasons · ${item.episode_count || '?'} episodes` + (item.year ? ` · ${item.year}` : '');

    let tvInfoHtml = '';
    if (item.plot) {
      tvInfoHtml += `<div style="font-size:0.85rem;color:var(--text-dim);line-height:1.6;margin-bottom:0.75rem;max-height:120px;overflow-y:auto">${esc(item.plot)}</div>`;
    }
    document.getElementById('selPath').innerHTML = tvInfoHtml;

    document.getElementById('selActions').innerHTML = '<div class="spinner"></div> Loading episodes...';
    tvEps.style.display = 'block';
    tvEps.innerHTML = '';

    // Fetch episodes
    try {
      const r = await fetch('api.php?action=episodes&show_id=' + item.id);
      const data = await r.json();
      document.getElementById('selActions').innerHTML = '';

      if (!data.seasons || Object.keys(data.seasons).length === 0) {
        tvEps.innerHTML = '<div style="color:var(--warning);font-size:0.85rem">No episodes found — run a library scrape</div>';
      } else {
        let html = '';
        for (const [sNum, eps] of Object.entries(data.seasons)) {
          if (sNum === '0') continue; // Skip specials/extras
          const firstEp = eps[0];
          const seasonFolder = firstEp.folder_path || '';
          const seasonId = `season_${item.id}_${sNum}`;
          html += `<div class="season-group">
            <div class="season-header" data-season-id="${seasonId}" onclick="toggleSeason(this)">
              <span class="arrow">▶</span>
              <span class="season-title">Season ${sNum} <span class="season-count">(${eps.length} episodes)</span></span>
              <div class="season-actions">
                ${seasonFolder ? `<button class="btn btn-find-season" onclick="event.stopPropagation();findSubtitlesForSeason('${escJs(item.title)}',${sNum},this)" title="Find subtitles online for every episode in this season">Search All Season</button>` : ''}
                <select class="season-lang" onclick="event.stopPropagation()" title="Subtitle language for Search All Season">
                  <option value="en">English</option>
                  <option value="es">Spanish</option>
                  <option value="fr">French</option>
                  <option value="de">German</option>
                  <option value="it">Italian</option>
                  <option value="pt">Portuguese</option>
                  <option value="ru">Russian</option>
                  <option value="zh">Chinese</option>
                  <option value="ja">Japanese</option>
                  <option value="ko">Korean</option>
                  <option value="ar">Arabic</option>
                  <option value="nl">Dutch</option>
                  <option value="pl">Polish</option>
                  <option value="sv">Swedish</option>
                  <option value="tr">Turkish</option>
                </select>
                ${seasonFolder ? `<button class="btn btn-sync-season" onclick="event.stopPropagation();syncSeason('${escJs(seasonFolder)}','${esc(item.title)} S${sNum}')" title="Re-sync every existing .srt in this season">Sync All</button>` : ''}
                ${seasonFolder ? `<button class="btn btn-scan-season" onclick="event.stopPropagation();scanSeasonFromHeader(this,'${escJs(seasonFolder)}','${seasonId}')" title="Scan every episode for embedded + external subtitles">Scan</button>` : ''}
              </div>
            </div>
            <div class="season-episodes" data-season-id="${seasonId}">`;
          eps.forEach(ep => {
            html += `<div class="episode-row" data-episode="${ep.episode}" data-season="${sNum}">
              <span class="ep-num">E${String(ep.episode).padStart(2,'0')}</span>
              <div class="ep-body">
                <div class="ep-title">${esc(ep.title || 'Episode ' + ep.episode)}</div>
              </div>
            </div>`;
          });
          html += '</div></div>';
        }
        tvEps.innerHTML = html;
      }
    } catch(e) {
      document.getElementById('selActions').innerHTML = '<span style="color:var(--danger)">Failed to load episodes</span>';
    }
  }

  panel.style.display = 'block';
  document.getElementById('scanPanel').style.display = 'none';
  document.getElementById('subSearchPanel').style.display = 'none';
}

function toggleSeason(el) {
  el.classList.toggle('open');
  const eps = el.nextElementSibling;
  eps.classList.toggle('open');
}

// Scan a season's folder and render results INLINE into each episode row.
// If the accordion is closed, open it first. Replaces the old top-level scanFolder() flow for TV.
async function scanSeasonFromHeader(btn, folderPath, seasonId) {
  const header = btn.closest('.season-header');
  const episodes = header.nextElementSibling;
  if (!header.classList.contains('open')) {
    header.classList.add('open');
    episodes.classList.add('open');
  }

  // Show a status pill on the header
  btn.disabled = true;
  const origText = btn.textContent;
  btn.textContent = 'Scanning…';

  try {
    const r = await fetch('api.php?action=scan', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ folder: folderPath, recursive: false })
    });
    const data = await r.json();
    if (data.error) {
      toast('Scan failed: ' + data.error, 'error');
      return;
    }

    // Build a map of filename → scan result so we can match episode rows by file name
    const pairsByFilename = {};
    (data.pairs || []).forEach(p => { pairsByFilename[p.video_filename] = p; });

    // Render scan data into each episode row in this season
    episodes.querySelectorAll('.episode-row').forEach(row => {
      // Find which pair matches this episode by checking pair.video against the episode's
      // expected filename pattern. We pick the pair whose filename contains s##e## of this row.
      const season = String(row.dataset.season).padStart(2, '0');
      const epNum = String(row.dataset.episode).padStart(2, '0');
      const pattern = new RegExp(`s${season}e${epNum}`, 'i');
      let match = null;
      for (const fname in pairsByFilename) {
        if (pattern.test(fname)) { match = pairsByFilename[fname]; break; }
      }
      if (match) {
        renderScannedEpisodeRow(row, match);
      } else {
        row.classList.add('episode-row-noscan');
      }
    });
  } catch(e) {
    toast('Scan error: ' + e.message, 'error');
  } finally {
    btn.disabled = false;
    btn.textContent = origText;
  }
}

// Render embedded subs + external subs + Find Subs button into an episode row (compact layout)
function renderScannedEpisodeRow(row, pair) {
  const FLAGS = { en:'🇬🇧',es:'🇪🇸',fr:'🇫🇷',de:'🇩🇪',it:'🇮🇹',pt:'🇧🇷',ru:'🇷🇺',zh:'🇨🇳',ja:'🇯🇵',ko:'🇰🇷',ar:'🇸🇦',nl:'🇳🇱',pl:'🇵🇱',sv:'🇸🇪',tr:'🇹🇷',da:'🇩🇰',fi:'🇫🇮',no:'🇳🇴',cs:'🇨🇿',hu:'🇭🇺',ro:'🇷🇴',he:'🇮🇱' };
  const flag = c => FLAGS[(c || '').toLowerCase().slice(0,2)] || '🌐';
  const LANG3to2 = { eng:'en',spa:'es',fre:'fr',fra:'fr',ger:'de',deu:'de',ita:'it',por:'pt',rus:'ru',chi:'zh',zho:'zh',jpn:'ja',kor:'ko',ara:'ar',dut:'nl',nld:'nl',pol:'pl',swe:'sv',tur:'tr',dan:'da',fin:'fi',nor:'no',nob:'no',cze:'cs',ces:'cs',hun:'hu',rum:'ro',ron:'ro',heb:'he' };
  const norm = c => LANG3to2[(c||'').toLowerCase()] || (c||'').toLowerCase().slice(0,2);

  // Friendly language full names for embedded subs (used as suffix in old design)
  const LANGNAMES = { en:'English',es:'Spanish',fr:'French',de:'German',it:'Italian',pt:'Portuguese',ru:'Russian',zh:'Chinese',ja:'Japanese',ko:'Korean',ar:'Arabic',nl:'Dutch',pl:'Polish',sv:'Swedish',tr:'Turkish',da:'Danish',fi:'Finnish',no:'Norwegian',cs:'Czech',hu:'Hungarian',ro:'Romanian',he:'Hebrew' };

  // Build embedded sub list with optional +more collapse
  const tracks = pair.embedded_tracks || [];
  const MAX_VISIBLE = 6;
  const visibleTracks = tracks.slice(0, MAX_VISIBLE);
  const hiddenTracks = tracks.slice(MAX_VISIBLE);

  // Reproduces the "ENG · SRT (text-based) — English" varied-font row from earlier design
  const renderTrack = t => {
    const codec = friendlyCodec(t.codec);
    const tag = t.forced ? '(forced)' : (codec.note === 'image-based' ? '(image-based)' : '(text-based)');
    const lang2 = norm(t.language);
    const langName = LANGNAMES[lang2] || (t.title || '');
    const titlePart = t.title && t.title !== langName
      ? ` — <span class="ep-sub-title">${esc(t.title)}</span>`
      : (langName ? ` — <span class="ep-sub-langname">${esc(langName)}</span>` : '');
    return `<div class="ep-sub-item">
      <span class="ep-sub-flag">${flag(lang2)}</span><span class="ep-sub-lang">${esc(t.language || '—')}</span> · <span class="ep-sub-codec">${esc(codec.name)}</span> <span class="ep-sub-tag">${tag}</span>${titlePart}
    </div>`;
  };

  let embeddedHtml = '';
  if (tracks.length === 0) {
    embeddedHtml = '<div class="ep-sub-empty">No embedded subtitles</div>';
  } else {
    embeddedHtml = visibleTracks.map(renderTrack).join('');
    if (hiddenTracks.length > 0) {
      const hiddenId = 'hidden_' + Math.random().toString(36).substr(2,8);
      embeddedHtml += `<button class="ep-sub-more" onclick="this.nextElementSibling.style.display='block';this.style.display='none'">+${hiddenTracks.length} more</button>`;
      embeddedHtml += `<div id="${hiddenId}" class="ep-sub-hidden" style="display:none">${hiddenTracks.map(renderTrack).join('')}</div>`;
    }
  }

  // External subs
  const subs = pair.subtitles || [];
  let externalHtml = '';
  if (subs.length === 0) {
    externalHtml = `<div class="ep-no-subs">No external subtitle files — embedded tracks above are already in sync with the video</div>`;
  } else {
    externalHtml = subs.map(sub => {
      const m = sub.filename.match(/\.([a-z]{2,3})\.(srt|ass|ssa|vtt|sub)$/i);
      const subLang = m ? norm(m[1]) : '';
      const backupBadge = sub.has_backup ? ' <span class="ep-sub-badge">backup ✓</span>' : '';
      return `<div class="ep-sub-item ep-sub-external">
        ${subLang ? `<span class="ep-sub-flag">${flag(subLang)}</span>` : ''}<span class="ep-sub-name" title="${esc(sub.path)}">${esc(sub.filename)}</span>
        <span class="ep-sub-size">${esc(sub.size_human || (sub.size_kb + ' KB'))}</span>
        ${backupBadge}
        <button class="btn btn-sync btn-row" onclick="syncOne('${escJs(pair.video)}','${escJs(sub.path)}','${escJs(pair.video_filename)}')">Sync</button>
        ${sub.has_backup ? `<button class="btn btn-restore btn-row" onclick="restoreOne('${escJs(sub.path)}')">Restore</button>` : ''}
      </div>`;
    }).join('');
  }

  // Resolution pill
  let resPill = '';
  if (pair.resolution_label) {
    resPill = `<span class="res-pill res-${pair.resolution_label.toLowerCase()}">${esc(pair.resolution_label)}</span>`;
  }

  // Preserve existing episode title (read before innerHTML wipe)
  const existingTitleEl = row.querySelector('.ep-title');
  const epTitle = existingTitleEl ? existingTitleEl.textContent : '';

  // Card layout per spec: E## + headline content + Find Subtitles button on ONE row,
  // then two columns below. Embedded box has its own background+border. External has none.
  row.innerHTML = `
    <span class="ep-num">E${String(row.dataset.episode).padStart(2,'0')}</span>
    <div class="ep-body">
      <div class="ep-headline">
        <span class="ep-title">${esc(epTitle)}</span>
        <span class="ep-sep">·</span>
        <span class="ep-file">${esc(pair.video_filename)}</span>
        <span class="ep-sep">·</span>
        <span class="ep-file-size">${esc(pair.size_human || (pair.size_mb + ' MB'))}</span>
        ${resPill}
      </div>
      <div class="ep-cols">
        <div class="ep-col ep-col-embedded">
          <div class="ep-col-head">Embedded Subtitle Tracks</div>
          ${embeddedHtml}
        </div>
        <div class="ep-col ep-col-external">
          <div class="ep-col-head">External Subtitles</div>
          ${externalHtml}
          <div class="ep-col-footer">
            <select class="ep-lang" title="Subtitle language">
              <option value="en">English</option>
              <option value="es">Spanish</option>
              <option value="fr">French</option>
              <option value="de">German</option>
              <option value="it">Italian</option>
              <option value="pt">Portuguese</option>
              <option value="ru">Russian</option>
              <option value="zh">Chinese</option>
              <option value="ja">Japanese</option>
              <option value="ko">Korean</option>
              <option value="ar">Arabic</option>
              <option value="nl">Dutch</option>
              <option value="pl">Polish</option>
              <option value="sv">Swedish</option>
              <option value="tr">Turkish</option>
            </select>
            <button class="btn btn-find-ep" onclick="findSubtitlesForVideoInline('${escJs(pair.video)}','${escJs(pair.video_filename)}',${row.dataset.season},${row.dataset.episode},this)">Search for Subtitles</button>
          </div>
        </div>
      </div>
    </div>
  `;
  row.classList.add('episode-row-scanned');
  // Default the per-episode language picker to the season header's current value
  const seasonLang = row.closest('.season-episodes').previousElementSibling.querySelector('.season-lang');
  const epLang = row.querySelector('.ep-lang');
  if (seasonLang && epLang) epLang.value = seasonLang.value;
}

// Per-episode find — uses the episode row's own language picker
async function findSubtitlesForVideoInline(videoPath, filename, season, episode, btn) {
  if (!currentItem) return;
  const langSel = btn.parentElement.querySelector('.ep-lang');
  const lang = langSel ? langSel.value : 'en';
  await executeSubtitleSearch({
    title: currentItem.title,
    year: currentItem.year,
    type: 'tv',
    season: Number(season),
    episode: Number(episode),
    language: lang,
    video_path: videoPath,
  }, currentItem.title + ` S${String(season).padStart(2,'0')}E${String(episode).padStart(2,'0')}`);
}

// Search-all-season uses the LANGUAGE PICKER inside the season header
async function findSubtitlesForSeason(showTitle, seasonNum, btn) {
  if (!currentItem) return;
  const langSel = btn.parentElement.querySelector('.season-lang');
  const lang = langSel ? langSel.value : 'en';
  await executeSubtitleSearch({
    title: showTitle,
    year: currentItem.year,
    type: 'tv',
    season: Number(seasonNum),
    language: lang,
  }, `${showTitle} — Season ${seasonNum}`);
}

// ── Folder Scan ────────────────────────────────────────────────────────
async function scanFolder(folderPath, recursive) {
  const scanPanel = document.getElementById('scanPanel');
  const scanResults = document.getElementById('scanResults');
  const scanSummary = document.getElementById('scanSummary');

  scanPanel.style.display = 'block';
  scanResults.innerHTML = '<div class="spinner"></div> Scanning folder...';
  scanSummary.textContent = '';

  // Scroll scan panel into view
  setTimeout(() => scanPanel.scrollIntoView({ behavior: 'smooth', block: 'start' }), 100);

  try {
    const r = await fetch('api.php?action=scan', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ folder: folderPath, recursive: !!recursive })
    });
    const data = await r.json();

    if (data.error) {
      scanResults.innerHTML = `<div style="color:var(--danger)">${esc(data.error)}</div>`;
      return;
    }

    scanSummary.textContent = `${data.total_videos} video(s), ${data.total_external_subs} subtitle file(s)`;

    if (!data.pairs || data.pairs.length === 0) {
      scanResults.innerHTML = '<div style="color:var(--warning)">No video files found in this folder</div>';
      return;
    }

    let html = '';
    // Flag/normalizer helpers (mirror what TV uses)
    const FLAGS_M = { en:'🇬🇧',es:'🇪🇸',fr:'🇫🇷',de:'🇩🇪',it:'🇮🇹',pt:'🇧🇷',ru:'🇷🇺',zh:'🇨🇳',ja:'🇯🇵',ko:'🇰🇷',ar:'🇸🇦',nl:'🇳🇱',pl:'🇵🇱',sv:'🇸🇪',tr:'🇹🇷',da:'🇩🇰',fi:'🇫🇮',no:'🇳🇴',cs:'🇨🇿',hu:'🇭🇺',ro:'🇷🇴',he:'🇮🇱' };
    const LANG3to2_M = { eng:'en',spa:'es',fre:'fr',fra:'fr',ger:'de',deu:'de',ita:'it',por:'pt',rus:'ru',chi:'zh',zho:'zh',jpn:'ja',kor:'ko',ara:'ar',dut:'nl',nld:'nl',pol:'pl',swe:'sv',tur:'tr',dan:'da',fin:'fi',nor:'no',nob:'no',cze:'cs',ces:'cs',hun:'hu',rum:'ro',ron:'ro',heb:'he' };
    const flagM = c => FLAGS_M[(LANG3to2_M[(c||'').toLowerCase()] || (c||'').toLowerCase().slice(0,2))] || '🌐';

    data.pairs.forEach(pair => {
      html += `<div class="file-pair">`;

      const findBtnHtml = (currentItem && currentItem.type === 'tv')
        ? `<button class="btn btn-scan pair-find-btn" onclick="findSubtitlesForVideo('${escJs(pair.video)}','${escJs(pair.video_filename)}')">Find Subtitles for This Episode</button>`
        : '';
      const resPillM = pair.resolution_label
        ? `<span class="res-pill res-${pair.resolution_label.toLowerCase()}">${esc(pair.resolution_label)}</span>`
        : '';
      html += `<div class="pair-header">
        <div class="pair-video" data-video-path="${esc(pair.video)}">${esc(pair.video_filename)} <span class="size">(${esc(pair.size_human || (pair.size_mb + ' MB'))})</span> ${resPillM}</div>
        ${findBtnHtml}
      </div>`;

      // Embedded tracks with flag + human-friendly codec names
      if (pair.embedded_tracks && pair.embedded_tracks.length > 0) {
        html += '<div class="embedded-tracks"><div class="embedded-label">Embedded Subtitle Tracks (info only — not modified)</div>';
        pair.embedded_tracks.forEach(t => {
          const codec = friendlyCodec(t.codec);
          const forced = t.forced ? ' · Forced' : '';
          const title = t.title ? ` — ${esc(t.title)}` : '';
          html += `<div class="embedded-track"><span class="ep-sub-flag">${flagM(t.language)}</span>${t.language} · ${codec.name} <span style="color:var(--text-faint);font-size:0.72rem">(${codec.note})</span>${forced}${title}</div>`;
        });
        html += '</div>';
      }

      // External subtitles
      if (!pair.subtitles || pair.subtitles.length === 0) {
        html += '<div class="no-subs">No external subtitle files — embedded tracks above are already in sync with the video</div>';
      } else {
        pair.subtitles.forEach(sub => {
          const m = sub.filename.match(/\.([a-z]{2,3})\.(srt|ass|ssa|vtt|sub)$/i);
          const subLang = m ? (LANG3to2_M[m[1].toLowerCase()] || m[1].toLowerCase().slice(0,2)) : '';
          const flagHtml = subLang ? `<span class="ep-sub-flag">${FLAGS_M[subLang] || '🌐'}</span>` : '';
          const backupBadge = sub.has_backup ? ' <span style="color:var(--success);font-size:0.7rem">✓ backup exists</span>' : '';
          html += `<div class="sub-row">
            ${flagHtml}<span class="sub-name">${esc(sub.filename)}${backupBadge}</span>
            <span class="sub-size">${esc(sub.size_human || (sub.size_kb + ' KB'))}</span>
            <button class="btn btn-sync" onclick="syncOne('${escJs(pair.video)}','${escJs(sub.path)}','${escJs(pair.video_filename)}')">Sync</button>
            ${sub.has_backup ? `<button class="btn btn-restore" onclick="restoreOne('${escJs(sub.path)}')">Restore</button>` : ''}
          </div>`;
        });
      }

      // Per-episode button now lives in the header row above
      html += '</div>';
    });

    scanResults.innerHTML = html;

    // Show "Find Subtitles" button if providers are configured
    const findBtn = document.getElementById('findSubsBtn');
    findBtn.style.display = 'flex';
    findBtn.dataset.folder = folderPath;
    // Update label based on content type
    const findAllBtn = document.getElementById('findAllBtn');
    if (findAllBtn) {
      const isTV = currentItem && currentItem.type === 'tv';
      findAllBtn.textContent = isTV ? 'Find Subtitles for Whole Season' : 'Find Subtitles Online';
    }
    // Hide per-episode buttons for movies (only one video anyway)
    // Set default language from settings
    fetch('api.php?action=get_settings').then(r => r.json()).then(s => {
      const langSel = document.getElementById('subSearchLang');
      if (langSel && s.sub_language) langSel.value = s.sub_language;
    }).catch(() => {});

  } catch(e) {
    scanResults.innerHTML = `<div style="color:var(--danger)">Scan error: ${esc(e.message)}</div>`;
  }
}

// ── Subtitle Search & Download ─────────────────────────────────────────
// Per-episode search — searches for one specific episode
async function findSubtitlesForVideo(videoPath, videoFilename) {
  const item = currentItem;
  if (!item) return;

  const language = document.getElementById('subSearchLang').value || 'en';

  // Extract season AND episode from this specific video
  let season = null, episode = null;
  const seMatch = videoFilename.match(/[Ss](\d{1,2})[Ee](\d{1,2})/);
  if (seMatch) {
    season = parseInt(seMatch[1]);
    episode = parseInt(seMatch[2]);
  }

  const label = (season && episode)
    ? `S${String(season).padStart(2,'0')}E${String(episode).padStart(2,'0')}`
    : videoFilename;

  await executeSubtitleSearch({
    title: item.title,
    year: item.year || null,
    imdb_id: item.imdb_id || '',
    language: language,
    season: season,
    episode: episode,
    video_path: videoPath,
  }, `${item.title} — ${label}`);
}

async function findSubtitles() {
  const item = currentItem;
  if (!item) {
    document.getElementById('subSearchPanel').style.display = 'block';
    document.getElementById('subSearchResults').innerHTML = '<div style="color:var(--danger)">No item selected</div>';
    return;
  }

  const language = document.getElementById('subSearchLang').value || 'en';

  const scanPanel = document.getElementById('scanPanel');
  const allVideos = scanPanel.querySelectorAll('.pair-video[data-video-path]');
  const firstVideo = allVideos.length > 0 ? allVideos[0] : null;

  // For TV shows, extract season; only pass episode for single-video scans
  let season = null, episode = null;
  if (item.type === 'tv' && firstVideo) {
    const fname = firstVideo.dataset.videoPath || '';
    const seMatch = fname.match(/[Ss](\d{1,2})[Ee](\d{1,2})/);
    if (seMatch) {
      season = parseInt(seMatch[1]);
      if (allVideos.length === 1) episode = parseInt(seMatch[2]);
    }
    if (!season) {
      const seasonMatch = fname.match(/Season\s+(\d{1,2})/i);
      if (seasonMatch) season = parseInt(seasonMatch[1]);
    }
  }

  const body = {
    title: item.title,
    year: item.year || null,
    imdb_id: item.imdb_id || '',
    language: language,
    season: season,
    episode: episode,
  };
  if (firstVideo) body.video_path = firstVideo.dataset.videoPath;

  const seasonLabel = season ? ` — Season ${season}` : '';
  await executeSubtitleSearch(body, item.title + seasonLabel);
}

// Shared search executor used by both whole-season and per-episode searches
async function executeSubtitleSearch(body, contextLabel) {
  const panel = document.getElementById('subSearchPanel');
  const results = document.getElementById('subSearchResults');
  const summary = document.getElementById('subSearchSummary');
  const context = document.getElementById('subSearchContext');
  const item = currentItem;

  // Build a rich context line so the user always knows what was searched.
  // Shows: Title (Year) · Season/Episode (TV only) · Language · Target file
  const langNames = {en:'English',es:'Spanish',fr:'French',de:'German',it:'Italian',pt:'Portuguese',ru:'Russian',zh:'Chinese',ja:'Japanese',ko:'Korean',ar:'Arabic',nl:'Dutch',pl:'Polish',sv:'Swedish',tr:'Turkish',da:'Danish',fi:'Finnish',no:'Norwegian',cs:'Czech',hu:'Hungarian',ro:'Romanian',he:'Hebrew'};
  const langDisplay = langNames[body.language] || (body.language || 'en').toUpperCase();
  let titleLine = esc(body.title || item.title);
  if (body.year || item.year) titleLine += ` <span class="ctx-meta">(${esc(body.year || item.year)})</span>`;
  if (body.season) {
    const epPart = body.episode
      ? `S${String(body.season).padStart(2,'0')}E${String(body.episode).padStart(2,'0')}`
      : `Season ${body.season} · all episodes`;
    titleLine += ` <span class="ctx-meta">· ${esc(epPart)}</span>`;
  }
  let fileLine = '';
  if (body.video_path) {
    const parts = body.video_path.split('/');
    fileLine = `<div class="ctx-file">${esc(parts[parts.length - 1])}</div>`;
  }
  context.innerHTML = `
    <div class="ctx-title">Results for: ${titleLine}
      <span class="ctx-lang">🔎 ${esc(langDisplay)} Subtitles</span>
    </div>
    ${fileLine}
  `;
  context.style.display = 'block';

  panel.style.display = 'block';
  results.innerHTML = `<div class="spinner"></div> Searching subtitle providers for ${esc(contextLabel)}...`;
  summary.textContent = '';
  panel.scrollIntoView({ behavior: 'smooth', block: 'start' });

  // Find the video path for downloads
  const scanPanel = document.getElementById('scanPanel');
  const firstVideo = scanPanel.querySelector('.pair-video[data-video-path]');

  try {
    const r = await fetch('api.php?action=subtitle_search', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(body)
    });
    const data = await r.json();

    if (data.error) {
      results.innerHTML = `<div style="color:var(--danger)">${esc(data.error)}</div>`;
      return;
    }

    if (!data.results || data.results.length === 0) {
      results.innerHTML = '<div style="color:var(--text-dim);padding:1rem">No subtitles found. Try a different language or enable more providers in Settings.</div>';
      return;
    }

    // Filter out low-quality releases (TELESYNC, cam, HDCAM, etc.) — these are pirated
    // pre-release captures and never match real Blu-ray/WEB-DL files. The user's library
    // is high-quality content, so these subtitles will never sync correctly.
    const BAD_RELEASE_PATTERNS = [
      /\bTELESYNC\b/i, /\bTELECINE\b/i, /\bHDCAM\b/i, /\bHDTC\b/i,
      /\bWORKPRINT\b/i, /\bTS-?Screener\b/i, /\bHC\.HDRip\b/i,
      /(^|[.\-_\s])CAM([.\-_\s]|$)/i,
    ];
    const isBadQuality = s => {
      const text = (s.filename || '') + ' ' + (s.release || '');
      return BAD_RELEASE_PATTERNS.some(rx => rx.test(text));
    };
    const filteredResults = data.results.filter(s => !isBadQuality(s));
    if (filteredResults.length === 0 && data.results.length > 0) {
      results.innerHTML = '<div style="color:var(--text-dim);padding:1rem">All results were filtered (TELESYNC / cam / HDCAM low-quality releases). Try a different language or provider.</div>';
      return;
    }

    summary.textContent = `${filteredResults.length} result(s) from ${new Set(filteredResults.map(r => r.provider_name)).size} provider(s)`;

    // Use the video path from the search body (per-episode) or fall back to first video
    const videoPath = body.video_path || (firstVideo ? (firstVideo.dataset.videoPath || '') : '');

    results.innerHTML = filteredResults.map(sub => {
      const provClass = sub.match_type === 'hash' ? 'sub-result-provider sub-result-hash' : 'sub-result-provider';
      const hi = sub.hearing_impaired ? '<span class="sub-result-hi">CC/HI</span>' : '';
      const mt = sub.machine_translated ? '<span class="sub-result-mt">MT</span>' : '';
      const downloads = sub.download_count > 0 ? `${Number(sub.download_count).toLocaleString()} downloads` : '';
      const uploader = sub.uploader ? 'by ' + sub.uploader : '';
      const matchBadge = sub.match_type === 'hash' ? '<span class="sub-result-match-hash">HASH MATCH</span>' : '';

      // Build meta WITHOUT duplicating the filename/release that's already on the line above.
      // If release equals filename, only show downloads + uploader.
      const releaseDifferent = sub.release && sub.release !== (sub.filename || '');
      const metaParts = [];
      if (releaseDifferent) metaParts.push(sub.release);
      if (downloads) metaParts.push(downloads);
      if (uploader)  metaParts.push(uploader);
      const meta = metaParts.join(' · ');
      const langNames = {en:'English',es:'Spanish',fr:'French',de:'German',it:'Italian',pt:'Portuguese',ru:'Russian',zh:'Chinese',ja:'Japanese',ko:'Korean',ar:'Arabic',nl:'Dutch',pl:'Polish',sv:'Swedish',tr:'Turkish',da:'Danish',fi:'Finnish',no:'Norwegian',cs:'Czech',hu:'Hungarian',ro:'Romanian',he:'Hebrew'};
      const langDisplay = langNames[sub.language] || (sub.language || '').toUpperCase();

      // Build a provider page URL (where viable) so the pill becomes a clickable link.
      // For SubDL we intentionally do NOT use the file_id — it's a download URL with
      // an API key in it. We send users to a search page using the title instead.
      let providerUrl = null;
      const fid = sub.file_id || '';
      const titleForSearch = (item.title || '').trim();
      switch (sub.provider) {
        case 'opensubtitles':
          if (fid && /^\d+$/.test(fid)) providerUrl = `https://www.opensubtitles.com/en/subtitles/${fid}`;
          else providerUrl = 'https://www.opensubtitles.com/';
          break;
        case 'subdl':
          // Avoid leaking the API key embedded in file_id; link to a search instead.
          providerUrl = titleForSearch
            ? `https://subdl.com/search/${encodeURIComponent(titleForSearch)}`
            : 'https://subdl.com/';
          break;
        case 'podnapisi':
          if (fid) providerUrl = `https://www.podnapisi.net/subtitles/${fid}`;
          else providerUrl = 'https://www.podnapisi.net/';
          break;
        case 'yify':
          if (fid.startsWith('http')) providerUrl = fid;
          else if (fid.startsWith('/')) providerUrl = `https://yifysubtitles.ch${fid}`;
          else providerUrl = 'https://yifysubtitles.ch/';
          break;
        case 'addic7ed':
          // file_id is a /original or /updated download path, not a viewable page.
          // Best we can do is link to the homepage; suppress the link for that single case
          // to avoid promising something useful.
          providerUrl = null;
          break;
        case 'gestdown':
          // API-only service — no per-subtitle pages exist.
          providerUrl = null;
          break;
        default:
          providerUrl = null;
      }

      const pill = providerUrl
        ? `<a class="${provClass} sub-result-provider-link" href="${esc(providerUrl)}" target="_blank" rel="noopener" title="Open subtitle link">${esc(sub.provider_name)}</a>`
        : `<span class="${provClass}" title="No direct link available from this provider">${esc(sub.provider_name)}</span>`;

      return `<div class="sub-result-wrap">
        <div class="sub-result">
          <div class="sub-result-info">
            <div class="sub-result-name">${esc(sub.filename || sub.release || 'Unknown')}${hi}${mt}</div>
            <div class="sub-result-meta">${esc(meta)}${matchBadge}</div>
          </div>
          <span class="sub-result-lang">${esc(langDisplay)}</span>
          ${pill}
          <button class="btn btn-preview" onclick="previewSub('${escJs(sub.provider)}','${escJs(sub.file_id)}',this)" title="Peek at the subtitle text before downloading">Preview</button>
          <button class="btn btn-download-sync" onclick="downloadSub('${escJs(sub.provider)}','${escJs(sub.file_id)}','${escJs(videoPath)}','${escJs(item.title)}','${escJs(sub.language || 'en')}',true)">Download & Sync</button>
          <button class="btn btn-download-only" onclick="downloadSub('${escJs(sub.provider)}','${escJs(sub.file_id)}','${escJs(videoPath)}','${escJs(item.title)}','${escJs(sub.language || 'en')}',false)">Download</button>
          <div class="sub-preview" style="display:none"></div>
        </div>
      </div>`;
    }).join('');

  } catch(e) {
    results.innerHTML = `<div style="color:var(--danger)">Search error: ${esc(e.message)}</div>`;
  }
}

async function previewSub(provider, fileId, btn) {
  // Locate the preview container for this row (now lives inside .sub-result)
  const card = btn.closest('.sub-result');
  const previewBox = card.querySelector('.sub-preview');

  // Toggle behavior: clicking Preview again closes it
  if (previewBox.style.display !== 'none' && previewBox.dataset.loaded === '1') {
    previewBox.style.display = 'none';
    previewBox.innerHTML = '';
    previewBox.dataset.loaded = '0';
    btn.textContent = 'Preview';
    return;
  }

  btn.disabled = true;
  btn.textContent = 'Loading…';
  previewBox.style.display = 'block';
  previewBox.innerHTML = '<div class="sub-preview-loading"><span class="spinner"></span> Downloading preview…</div>';

  try {
    const r = await fetch('api.php?action=subtitle_preview', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ provider: provider, file_id: fileId })
    });
    const data = await r.json();
    if (!data.ok) {
      previewBox.innerHTML = `<div class="sub-preview-error">Preview failed: ${esc(data.error || 'unknown')}</div>
        <button class="sub-preview-close" onclick="closePreview(this)" title="Close preview">×</button>`;
      previewBox.dataset.loaded = '1';
      btn.disabled = false;
      btn.textContent = 'Preview';
      return;
    }

    const lines = (data.lines || []).map(l => esc(l)).join('\n');
    previewBox.innerHTML = `
      <div class="sub-preview-head">
        <span>Preview · first ${data.shown} dialogue lines</span>
        <button class="sub-preview-close" onclick="closePreview(this)" title="Close preview">×</button>
      </div>
      <pre class="sub-preview-pre">${lines}</pre>
    `;
    previewBox.dataset.loaded = '1';
  } catch(e) {
    previewBox.innerHTML = `<div class="sub-preview-error">Preview error: ${esc(e.message)}</div>
      <button class="sub-preview-close" onclick="closePreview(this)" title="Close preview">×</button>`;
    previewBox.dataset.loaded = '1';
  } finally {
    btn.disabled = false;
    btn.textContent = 'Preview';
  }
}

function closePreview(btn) {
  const previewBox = btn.closest('.sub-preview');
  previewBox.style.display = 'none';
  previewBox.innerHTML = '';
  previewBox.dataset.loaded = '0';
}

async function downloadSub(provider, fileId, videoPath, title, language, autoSync) {
  const action = autoSync ? 'Download & sync' : 'Download';
  if (!confirm(`${action} this subtitle?`)) return;

  toast('Downloading subtitle...', 'info');

  try {
    const r = await fetch('api.php?action=subtitle_download', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        provider: provider,
        file_id: fileId,
        video_path: videoPath,
        title: title,
        language: language,
        auto_sync: autoSync,
      })
    });
    const data = await r.json();

    if (data.error) { toast('Error: ' + data.error, 'error'); return; }

    if (data.ok) {
      if (data.sync_queued) {
        toast('Downloaded and queued for sync', 'success');
        document.querySelectorAll('.nav a[data-page]').forEach(l => l.classList.remove('active'));
        document.querySelector('[data-page="queue"]').classList.add('active');
        document.getElementById('pageSearch').style.display = 'none';
        document.getElementById('pageQueue').style.display = 'block';
        startQueuePolling();
      } else {
        toast('Subtitle downloaded successfully', 'success');
        // Re-scan to show the new file
        const folder = document.getElementById('findSubsBtn').dataset.folder;
        if (folder) scanFolder(folder);
      }
    }
  } catch(e) { toast('Error: ' + e.message, 'error'); }
}

// Translate technical codec names to human-friendly
function friendlyCodec(codec) {
  const map = {
    'HDMV_PGS_SUBTITLE': { name: 'PGS', note: 'Blu-ray image-based, cannot be synced' },
    'SUBRIP': { name: 'SRT', note: 'text-based' },
    'SRT': { name: 'SRT', note: 'text-based' },
    'ASS': { name: 'ASS', note: 'styled text' },
    'SSA': { name: 'SSA', note: 'styled text' },
    'MOV_TEXT': { name: 'Text', note: 'MP4 text-based' },
    'WEBVTT': { name: 'WebVTT', note: 'web text-based' },
    'DVB_SUBTITLE': { name: 'DVB', note: 'broadcast image-based' },
    'DVD_SUBTITLE': { name: 'VobSub', note: 'DVD image-based, cannot be synced' },
    'DVDSUB': { name: 'VobSub', note: 'DVD image-based, cannot be synced' },
  };
  return map[codec.toUpperCase()] || { name: codec, note: 'subtitle track' };
}

// ── Sync Operations ────────────────────────────────────────────────────
async function syncOne(videoPath, subPath, title) {
  if (!confirm(`Sync this subtitle?\n\n${subPath.split('/').pop()}\n\nA backup will be created first.`)) return;

  try {
    const r = await fetch('api.php?action=sync', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ video: videoPath, subtitle: subPath, title: title })
    });
    const data = await r.json();
    if (data.error) { toast(data.error, 'error'); return; }
    toast('Added to sync queue', 'info');
    // Switch to Queue tab
    document.querySelectorAll('.nav a[data-page]').forEach(l => l.classList.remove('active'));
    document.querySelector('[data-page="queue"]').classList.add('active');
    document.getElementById('pageSearch').style.display = 'none';
    document.getElementById('pageQueue').style.display = 'block';
    startQueuePolling();
  } catch(e) { toast('Error: ' + e.message, 'error'); }
}

async function syncSeason(folderPath, title) {
  if (!confirm(`Sync ALL external subtitles in this season folder?\n\nBackups will be created for each file.`)) return;

  try {
    const r = await fetch('api.php?action=sync_batch', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ folder: folderPath, recursive: false, title: title })
    });
    const data = await r.json();
    if (data.error) { toast(data.error, 'error'); return; }
    toast(`${data.count} subtitle(s) queued for sync`, 'info');
    // Switch to Queue tab
    document.querySelectorAll('.nav a[data-page]').forEach(l => l.classList.remove('active'));
    document.querySelector('[data-page="queue"]').classList.add('active');
    document.getElementById('pageSearch').style.display = 'none';
    document.getElementById('pageQueue').style.display = 'block';
    startQueuePolling();
  } catch(e) { toast('Error: ' + e.message, 'error'); }
}

async function restoreOne(subPath) {
  if (!confirm(`Restore subtitle from backup?\n\n${subPath.split('/').pop()}`)) return;

  try {
    const r = await fetch('api.php?action=restore', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ subtitle: subPath })
    });
    const data = await r.json();
    if (data.error) { toast(data.error, 'error'); return; }
    toast('Restored from backup', 'success');
    // Re-scan the folder
    const folder = subPath.substring(0, subPath.lastIndexOf('/'));
    scanFolder(folder);
  } catch(e) { toast('Error: ' + e.message, 'error'); }
}

// ── Queue ──────────────────────────────────────────────────────────────
const statusMessages = [
  { after: 0, text: 'Extracting audio track...' },
  { after: 10, text: 'Analyzing speech patterns...' },
  { after: 30, text: 'Computing subtitle alignments...' },
  { after: 60, text: 'Still processing — longer files take more time...' },
  { after: 120, text: 'Almost there — finalizing alignment...' },
  { after: 180, text: 'Large file — processing continues...' },
];

function getStatusMessage(elapsedSec) {
  let msg = statusMessages[0].text;
  for (const s of statusMessages) {
    if (elapsedSec >= s.after) msg = s.text;
  }
  return msg;
}

function formatElapsed(seconds) {
  if (seconds < 60) return Math.round(seconds) + 's';
  const m = Math.floor(seconds / 60);
  const s = Math.round(seconds % 60);
  return m + 'm ' + s + 's';
}

function parseSyncLog(log) {
  const r = { offset: null, framerate: null, elapsed: null, score: null };
  const m1 = log.match(/offset seconds:\s*([\d.-]+)/);
  if (m1) r.offset = m1[1];
  const m2 = log.match(/framerate scale factor:\s*([\d.]+)/);
  if (m2) r.framerate = m2[1];
  const m3 = log.match(/Completed in ([\d.]+)s/);
  if (m3) r.elapsed = m3[1];
  const m4 = log.match(/score:\s*([\d.]+)/);
  if (m4) r.score = m4[1];
  return r;
}

function toggleLog(id) {
  const el = document.getElementById('log-' + id);
  if (el) el.classList.toggle('open');
}

async function refreshQueue() {
  try {
    const r = await fetch('api.php?action=queue');
    const items = await r.json();
    const list = document.getElementById('queueList');
    const badge = document.getElementById('queueBadge');

    if (!items.length) {
      list.innerHTML = `<div class="queue-empty">
        <div class="queue-empty-icon">🎬</div>
        No sync jobs yet.<br>Search for a movie or TV show, scan its folder, and click Sync.
      </div>`;
      badge.innerHTML = '';
      badge.className = '';
      return;
    }

    const runningCount = items.filter(i => i.status === 'running' || i.status === 'pending').length;
    if (runningCount > 0) {
      badge.innerHTML = runningCount;
      badge.className = 'nav-badge nav-badge-active';
    } else {
      badge.innerHTML = '';
      badge.className = '';
    }

    // Remember which checkboxes were checked before re-rendering
    const checkedIds = new Set(
      Array.from(document.querySelectorAll('.qi-checkbox:checked')).map(cb => cb.dataset.queueId)
    );

    list.innerHTML = items.map(item => {
      const subFile = item.subtitle_path ? item.subtitle_path.split('/').pop() : '';
      const logId = 'log-' + item.id;

      // Format the log with proper line breaks
      let formattedLog = '';
      if (item.log) {
        formattedLog = esc(item.log)
          .replace(/✓/g, '<span class="log-ok">✓</span>')
          .replace(/✗/g, '<span class="log-err">✗</span>')
          .replace(/(INFO)/g, '<span class="log-dim">$1</span>');
      }

      // ── RUNNING STATE ──────────────────────────────────
      if (item.status === 'running') {
        let elapsedSec = 0;
        if (item.started_at) {
          const started = new Date(item.started_at.replace(' ', 'T') + 'Z');
          elapsedSec = (Date.now() - started.getTime()) / 1000;
        }
        const statusMsg = getStatusMessage(elapsedSec);
        const posterHtml = item.poster_url
          ? `<img class="qi-poster" src="${esc(item.poster_url)}" loading="lazy" onerror="this.className='qi-poster-empty';this.innerHTML='🎬'">`
          : `<div class="qi-poster-empty">🎬</div>`;

        return `<div class="queue-item queue-item-running">
          <input type="checkbox" class="qi-checkbox" data-queue-id="${item.id}">
          ${posterHtml}
          <div class="qi-content">
          <div class="qi-header">
            <div class="qi-icon qi-icon-running">⟳</div>
            <div class="qi-title-wrap">
              <div class="qi-title">Syncing: ${esc(item.media_title)}</div>
              <div class="qi-subtitle">${esc(subFile)}</div>
            </div>
          </div>
          <div class="qi-progress"><div class="qi-progress-bar"><div class="qi-progress-stripe"></div></div></div>
          <div class="qi-status">
            <span>${statusMsg}</span>
            <span class="qi-elapsed">${formatElapsed(elapsedSec)}</span>
          </div>
          ${formattedLog ? `<button class="qi-log-toggle" onclick="toggleLog(${item.id})">▸ Show technical log</button>
          <div class="qi-log" id="${logId}">${formattedLog}</div>` : ''}
          </div>
        </div>`;
      }

      // ── COMPLETED STATE ────────────────────────────────
      if (item.status === 'done') {
        const s = parseSyncLog(item.log || '');
        const offsetText = s.offset ? `Your subtitles were <strong>${s.offset} seconds</strong> out of sync. They've been corrected` : 'Subtitles have been aligned to the audio track';
        const backupMatch = (item.log || '').match(/Backup:\s*(.+)/);
        const backupName = backupMatch ? backupMatch[1] : '';
        const statsText = [
          s.elapsed ? 'Processed in ' + formatElapsed(parseFloat(s.elapsed)) : '',
          s.score ? 'Confidence: ' + Number(s.score).toLocaleString() : '',
        ].filter(Boolean).join('  ·  ');
        const posterHtml = item.poster_url
          ? `<img class="qi-poster" src="${esc(item.poster_url)}" loading="lazy" onerror="this.className='qi-poster-empty';this.innerHTML='🎬'">`
          : `<div class="qi-poster-empty">🎬</div>`;

        return `<div class="queue-item queue-item-done">
          <input type="checkbox" class="qi-checkbox" data-queue-id="${item.id}">
          ${posterHtml}
          <div class="qi-content">
          <div class="qi-header">
            <div class="qi-icon qi-icon-done">✓</div>
            <div class="qi-title-wrap">
              <div class="qi-title">${esc(item.media_title)} — <span class="qi-title-fixed">Subtitles Fixed</span></div>
              <div class="qi-subtitle">${esc(subFile)}</div>
            </div>
          </div>
          <div class="qi-progress"><div class="qi-progress-bar"><div class="qi-progress-done"></div></div></div>
          <div class="qi-summary">
            ${offsetText} and the original was backed up${backupName ? ' as <strong>' + esc(backupName) + '</strong>' : ''}.
          </div>
          ${statsText ? `<div class="qi-stats">${statsText}</div>` : ''}
          <button class="qi-log-toggle" onclick="toggleLog(${item.id})">▸ Show technical log</button>
          <div class="qi-log" id="${logId}">${formattedLog}</div>
          </div>
        </div>`;
      }

      // ── FAILED STATE ───────────────────────────────────
      if (item.status === 'failed') {
        const posterHtml = item.poster_url
          ? `<img class="qi-poster" src="${esc(item.poster_url)}" loading="lazy" onerror="this.className='qi-poster-empty';this.innerHTML='🎬'">`
          : `<div class="qi-poster-empty">🎬</div>`;

        return `<div class="queue-item queue-item-failed">
          <input type="checkbox" class="qi-checkbox" data-queue-id="${item.id}">
          ${posterHtml}
          <div class="qi-content">
          <div class="qi-header">
            <div class="qi-icon qi-icon-failed">✗</div>
            <div class="qi-title-wrap">
              <div class="qi-title">${esc(item.media_title)} — Sync Failed</div>
              <div class="qi-subtitle">${esc(subFile)}</div>
            </div>
          </div>
          <div class="qi-progress"><div class="qi-progress-bar"><div class="qi-progress-failed"></div></div></div>
          <div class="qi-summary" style="color:var(--text-dim)">
            ffsubsync could not align the subtitles. The original file was not modified. Your backup is safe.
          </div>
          <button class="qi-log-toggle" onclick="toggleLog(${item.id})">▸ Show technical log</button>
          <div class="qi-log" id="${logId}">${formattedLog}</div>
          </div>
        </div>`;
      }

      // ── PENDING STATE ──────────────────────────────────
      const posterHtml = item.poster_url
        ? `<img class="qi-poster" src="${esc(item.poster_url)}" loading="lazy" onerror="this.className='qi-poster-empty';this.innerHTML='🎬'">`
        : `<div class="qi-poster-empty">🎬</div>`;

      return `<div class="queue-item">
        <input type="checkbox" class="qi-checkbox" data-queue-id="${item.id}">
        ${posterHtml}
        <div class="qi-content">
        <div class="qi-header">
          <div class="qi-icon qi-icon-pending">◷</div>
          <div class="qi-title-wrap">
            <div class="qi-title">${esc(item.media_title)}</div>
            <div class="qi-subtitle">${esc(subFile)}</div>
          </div>
        </div>
        <div class="qi-status"><span>Waiting in queue...</span></div>
        </div>
      </div>`;
    }).join('');

    // Restore previously checked checkboxes
    checkedIds.forEach(id => {
      const cb = document.querySelector(`.qi-checkbox[data-queue-id="${id}"]`);
      if (cb) cb.checked = true;
    });

    if (runningCount > 0) {
      startQueuePolling();
    } else {
      stopQueuePolling();
      loadStats();
    }

  } catch(e) {}
}

function startQueuePolling() {
  if (queueTimer) return;
  queueTimer = setInterval(refreshQueue, 2000);
  refreshQueue();
}

function stopQueuePolling() {
  if (queueTimer) { clearInterval(queueTimer); queueTimer = null; }
}

async function clearQueue() {
  if (!confirm('Clear ALL items from the queue?')) return;
  await fetch('api.php?action=clear_queue');
  refreshQueue();
}

async function clearFailed() {
  await fetch('api.php?action=clear_failed');
  toast('Failed items cleared', 'info');
  refreshQueue();
}

async function clearSelected() {
  const checked = document.querySelectorAll('.qi-checkbox:checked');
  if (checked.length === 0) {
    toast('No items selected — check the boxes on cards first', 'info');
    return;
  }
  const ids = Array.from(checked).map(cb => parseInt(cb.dataset.queueId));
  await fetch('api.php?action=clear_selected', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({ ids: ids })
  });
  toast(`${ids.length} item(s) cleared`, 'info');
  refreshQueue();
}

// ── Helpers ────────────────────────────────────────────────────────────
function esc(s) {
  if (!s) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function escJs(s) { return String(s || '').replace(/\\/g,'\\\\').replace(/'/g,"\\'"); }

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
// Set initial button state
(function(){
  var t = document.documentElement.getAttribute('data-theme') || 'light';
  document.getElementById('themeBtn').textContent = t === 'dark' ? '☀️' : '🌙';
})();

// Auto-poll queue on load if there are active jobs
refreshQueue();

// ── Floating scroll-to-top/bottom buttons ──────────────────────────────
(function(){
  const wrap = document.createElement('div');
  wrap.className = 'scroll-fab-wrap';
  wrap.innerHTML = `
    <button class="scroll-fab" id="scrollFabTop"    title="Scroll to top">▲</button>
    <button class="scroll-fab" id="scrollFabBottom" title="Scroll to bottom">▼</button>
  `;
  document.body.appendChild(wrap);
  document.getElementById('scrollFabTop').onclick    = () => window.scrollTo({ top: 0, behavior: 'smooth' });
  document.getElementById('scrollFabBottom').onclick = () => window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
  // Show after 300px scroll
  const onScroll = () => {
    if (window.scrollY > 300) wrap.classList.add('visible');
    else wrap.classList.remove('visible');
  };
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
})();
</script>
</body>
</html>
