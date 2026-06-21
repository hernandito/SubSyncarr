# SubSyncarr

<p align="center">
  <img src="full-logo.png" alt="SubSyncarr" width="640">
</p>

<p align="center">
  <strong>Fix out-of-sync subtitles from your couch.</strong><br>
  Search your Kodi, Plex, Emby, or Jellyfin library with poster art, scan for subtitle files, and fix timing with one click.
</p>

<p align="center">
  <a href="https://hub.docker.com/r/hernandito/subsyncarr"><img src="https://img.shields.io/docker/pulls/hernandito/subsyncarr?style=flat-square" alt="Docker Pulls"></a>
  <a href="https://github.com/hernandito/SubSyncarr/releases"><img src="https://img.shields.io/github/v/release/hernandito/SubSyncarr?style=flat-square" alt="Release"></a>
</p>

[![Buy Me A Coffee](https://cdn.buymeacoffee.com/buttons/v2/default-yellow.png)](https://www.buymeacoffee.com/hernandito)



---

## What It Does

SubSyncarr connects to your **Kodi**, **Plex**, **Emby**, or **Jellyfin** media library, scrapes your movies and TV shows, and gives you a clean web interface to fix subtitle timing — and now **download subtitles** directly from multiple providers — all from your phone, tablet, or browser.

Have you ever sat down to watch a movie or show and find that the subs are out of sync? You hunt for other subs and struggle to find something that works. With SubSyncarr, a movie/series search, a couple button clicks, and in about 3 minutes, you will have a properly synchronized subtitle; all **without leaving the couch!**

It uses [ffsubsync](https://github.com/smacke/ffsubsync) under the hood, which analyzes the audio track of your video and aligns the subtitle timing to match speech patterns. It works with **any language combination** — English audio with English subs, Spanish audio with English subs, or any other pairing.

### Key Features

- **Kodi, Plex, Emby & Jellyfin support** — connect to any of the four major media servers; switch anytime from Settings
- **Search with poster art** — type a movie or TV show name and see results with posters, ratings, genres, and plot summaries
- **Download subtitles** — search and download from OpenSubtitles.com, SubDL, Gestdown, YIFY, Podnapisi, and Addic7ed, in any language
- **Download & Sync in one click** — find a subtitle, download it, and auto-sync the timing in a single step
- **Per-episode or whole-season search** — grab subtitles for the one episode you're watching, or the entire season at once
- **Multi-language** — download English, Spanish, French, and 18+ other languages; each saved with the correct language suffix (.en.srt, .es.srt)
- **Smart folder scanning** — detects external subtitle files (.srt, .ass, .ssa, .sub, .vtt) and shows embedded tracks for reference
- **One-click sync** — ffsubsync analyzes the audio and corrects subtitle timing automatically
- **Your existing Subtitles are SAFE** — every subtitle is backed up before modification, with one-click restore
- **Batch TV season sync** — fix an entire season's subtitles in one click
- **Live sync queue** — animated progress bar, elapsed timer, human-readable results, and per-job management (clear selected/failed/all)
- **Auto-detection** — automatically detects your library paths during setup
- **Couch-friendly** — large posters, big tap targets, designed for phone and tablet use
- **Does NOT touch embedded subtitles** — these subs are assumed to be correct as they come from the source
- **Themes** — quickly toggle between Light and Dark theme

---

## Screenshots

### Search & Select a Movie
<img src="screenshots/search-movie.png" alt="Movie Detail" width="800">

### Search and Select a TV Show
<img src="screenshots/search-tv.png" alt="Search TV" width="800">

### Click Sync
<img src="screenshots/search-movies.png" alt="Search Movies" width="800">

### Sync In Progress
<img src="screenshots/inprogress-queue.png" alt="Sync In Progress" width="800">

### Sync Complete
<img src="screenshots/sync-queue.png" alt="Sync Complete" width="800">

### Settings — Auto-Detection Wizard
<img src="screenshots/settings.png" alt="Settings" width="800">

---

## Installation

### unRAID (Community Applications)

Search for **SubSyncarr** in Community Applications and click Install. Configure:

| Field | Value |
|-------|-------|
| **Movies Folder** | Your unRAID movies path (e.g. `/mnt/user/Media/Movies`) |
| **TV Shows Folder** | Your unRAID TV shows path (e.g. `/mnt/user/Media/TV`) |
| **Config** | `/mnt/user/appdata/subsyncarr` |
| **WebUI Port** | `5889` |

### Docker Run

```bash
docker run -d \
  --name subsyncarr \
  -p 5889:5889 \
  -v /path/to/appdata/subsyncarr:/config \
  -v /path/to/movies:/movies \
  -v /path/to/tv:/tv \
  -e TZ=America/New_York \
  -e SCRAPE_INTERVAL=12 \
  hernandito/subsyncarr:latest
```

### Docker Compose

```yaml
services:
  subsyncarr:
    image: hernandito/subsyncarr:latest
    container_name: subsyncarr
    ports:
      - 5889:5889
    volumes:
      - /path/to/appdata/subsyncarr:/config
      - /path/to/movies:/movies
      - /path/to/tv:/tv
    environment:
      - TZ=America/New_York
      - SCRAPE_INTERVAL=12
    restart: unless-stopped
```

---

## First-Time Setup

1. Open `http://YOUR-IP:5889` — you'll be redirected to Settings
2. Select your media source: **Kodi**, **Plex**, **Emby**, or **Jellyfin**

### If using Kodi:
3. Enter your Kodi host, port, username, and password
4. Make sure HTTP control is enabled in Kodi: Settings → Services → Control → "Allow remote control via HTTP"

### If using Plex:
3. Enter your Plex server IP and port (default 32400)
4. Enter your Plex token (see [How to find your Plex token](#how-to-find-your-plex-token) below)

### If using Emby:
3. Enter your Emby server IP and port (default 8096)
4. Create an API key: Emby Dashboard → Settings → Advanced → API Keys → click + → name it "SubSyncarr"

### If using Jellyfin:
3. Enter your Jellyfin server IP and port (default 8096)
4. Create an API key: Jellyfin Dashboard → Advanced → API Keys → click + → name it "SubSyncarr"

### Then for both:
5. Click **Test Connection** to verify
6. Click **Detect Library Paths** — SubSyncarr queries your media server and auto-detects where your media lives
7. Verify both Movies and TV show green ✓ checkmarks (confirms Docker volumes are mapped correctly)
8. Click **Save Settings**
9. Click **Scrape Library Now** — this takes 2-3 minutes for large libraries
10. Go to the **Search** page and start fixing subtitles!

---

## How to Find Your Plex Token

**Method 1 — From Plex Web (recommended):**
1. Open [app.plex.tv/desktop](https://app.plex.tv/desktop) in your browser and sign in
2. Navigate to any movie in your library
3. Click the **⋮** menu → **Get Info** → **View XML**
4. A new tab opens — look at the URL bar and copy the value after `X-Plex-Token=`

**Method 2 — From your unRAID terminal:**
```bash
grep -o 'PlexOnlineToken="[^"]*"' "/mnt/user/appdata/PlexMediaServer/Library/Application Support/Plex Media Server/Preferences.xml"
```

---

## How It Works

1. **Search** — type a movie or TV show name
2. **Scan** — click the result, then "Scan for Subtitles" to see what files exist in the folder
3. **Sync** — click the Sync button next to any external subtitle file
4. **Wait** — ffsubsync extracts the audio, analyzes speech patterns, and aligns the subtitle timing (1-3 minutes for movies, 30-60 seconds for TV episodes)
5. **Done** — the corrected subtitle replaces the original, with a backup created automatically

### What ffsubsync does under the hood

It extracts the audio track, creates a speech-vs-silence fingerprint, does the same with the subtitle timing, and uses FFT to find the best alignment. It handles:

- **Constant offset** — subtitles are X seconds early/late throughout
- **Frame-rate drift** — subtitles start fine but gradually desync
- **Segment shifts** — different cuts of the same film

It does NOT transcribe audio — it's language-agnostic and works with any language combination.

### What it does NOT touch

- **Embedded subtitle tracks** are never modified — they're displayed for reference only
- **Video files** are never modified
- Only **external sidecar subtitle files** (.srt, .ass, .ssa, .sub, .vtt) are processed

---

## Configuration

| Environment Variable | Default | Description |
|---------------------|---------|-------------|
| `TZ` | `America/New_York` | Container timezone |
| `SCRAPE_INTERVAL` | `12` | Auto-scrape interval in hours (6, 12, or 24) |
| `PUID` | `99` | User ID (99 = nobody on unRAID) |
| `PGID` | `100` | Group ID (100 = users on unRAID) |

| Volume | Purpose |
|--------|---------|
| `/config` | Persistent database and settings |
| `/movies` | Your movies folder |
| `/tv` | Your TV shows folder |

---

## Roadmap

- [x] Kodi library scraping (movies + TV episodes)
- [x] Plex library scraping (movies + TV episodes)
- [x] Emby library scraping (movies + TV episodes)
- [x] Jellyfin library scraping (movies + TV episodes)
- [x] Poster-rich search with plot summaries
- [x] One-click subtitle sync with ffsubsync
- [x] Batch TV season sync
- [x] Backup and restore system
- [x] Auto-detection of library paths (all sources)
- [x] Light/dark theme
- [x] Subtitle download from 6 providers (OpenSubtitles.com, SubDL, Gestdown, YIFY, Podnapisi, Addic7ed)
- [x] Download + sync in one step
- [x] Per-episode and whole-season subtitle search
- [x] Multi-language download with correct language suffixes (.en.srt, .es.srt, etc.)
- [x] Queue management (clear selected / failed / all)
- [ ] 🔜 **Subtitle Health Scanner** — background scan of your entire library against your preferred language combinations, flagging missing or potentially-unsynced subtitles for batch processing
- [ ] 🔜 More subtitle providers (by request via GitHub issues)

## Subtitle Providers

| Provider | Auth | Best For | Status |
|----------|------|----------|--------|
| OpenSubtitles.com | API key + login | Everything, hash matching | Stable |
| SubDL | API key | Curated, high quality | Stable |
| Gestdown | None | TV series, European | Stable |
| YIFY Subtitles | None | Movies | Stable |
| Podnapisi | None | International | Depends on site uptime |
| Addic7ed | Optional login | TV series | Experimental (may be rate-limited) |

To enable providers: Settings → Subtitle Providers → check the ones you want, enter any required API keys, and click Test to verify.

---

## Support

If you find SubSyncarr useful, consider starring the repo ⭐

For bugs and feature requests, please [open an issue](https://github.com/hernandito/SubSyncarr/issues).

---

## License

MIT License
