<?php
/**
 * SubSyncarr API Router
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/config/php-errors.log');

header('Content-Type: application/json');

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        echo json_encode([
            'error' => 'PHP Fatal: ' . $error['message'],
            'file' => basename($error['file']),
            'line' => $error['line'],
        ]);
    }
});

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/sync.php';

function getScraper() {
    $sourceType = DB::getSetting('source_type', 'kodi');
    switch ($sourceType) {
        case 'plex':
            require_once __DIR__ . '/includes/scraper_plex.php';
            return new PlexScraper();
        case 'emby':
            require_once __DIR__ . '/includes/scraper_emby.php';
            return new EmbyScraper('emby');
        case 'jellyfin':
            require_once __DIR__ . '/includes/scraper_emby.php';
            return new EmbyScraper('jellyfin');
        default:
            require_once __DIR__ . '/includes/scraper.php';
            return new KodiScraper();
    }
}

/**
 * Tolerant JSON extractor. Python providers may emit warnings or debug lines on stderr
 * which we capture (2>&1). We try a strict decode first; on failure, we look for the
 * last well-formed JSON object in the output and decode that.
 */
function decodeJsonTolerant(?string $raw): ?array {
    if ($raw === null || $raw === '') return null;
    $direct = json_decode($raw, true);
    if (is_array($direct)) return $direct;
    // Find the last "{" and try parsing from there to the end.
    $lastBrace = strrpos($raw, '{');
    while ($lastBrace !== false) {
        $candidate = substr($raw, $lastBrace);
        $decoded = json_decode($candidate, true);
        if (is_array($decoded)) return $decoded;
        $lastBrace = strrpos(substr($raw, 0, $lastBrace), '{');
    }
    return null;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {

        case 'health':
            echo json_encode(['status' => 'ok', 'timestamp' => date('c')]);
            break;

        case 'search':
            $q = trim($_GET['q'] ?? '');
            if (strlen($q) < 2) { echo '[]'; break; }
            $movies = DB::searchMovies($q, 15);
            foreach ($movies as &$m) { $m['type'] = 'movie'; } unset($m);
            $shows = DB::searchTVShows($q, 15);
            foreach ($shows as &$s) { $s['type'] = 'tv'; } unset($s);
            $all = array_merge($movies, $shows);
            usort($all, fn($a, $b) => strcasecmp($a['title'], $b['title']));
            echo json_encode(array_slice($all, 0, 20));
            break;

        case 'episodes':
            $showId = (int) ($_GET['show_id'] ?? 0);
            if (!$showId) { echo json_encode(['error' => 'show_id required']); break; }
            $episodes = DB::getEpisodes($showId);
            $seasons = [];
            foreach ($episodes as $ep) { $seasons[$ep['season']][] = $ep; }
            echo json_encode(['show_id' => $showId, 'seasons' => $seasons]);
            break;

        case 'scan':
            $input = json_decode(file_get_contents('php://input'), true);
            $folder = $input['folder'] ?? $_GET['folder'] ?? '';
            $recursive = (bool) ($input['recursive'] ?? false);
            if (!$folder) { echo json_encode(['error' => 'folder path required']); break; }
            // Paths are already container paths (/movies/... or /tv/...)
            if (!is_dir($folder)) {
                echo json_encode(['error' => "Folder not found: {$folder}. Check your Docker volume mounts."]);
                break;
            }
            echo json_encode(SyncHelper::scanFolder($folder, $recursive));
            break;

        case 'sync':
            $input = json_decode(file_get_contents('php://input'), true);
            $video = $input['video'] ?? '';
            $subtitle = $input['subtitle'] ?? '';
            $title = $input['title'] ?? basename(dirname($video));
            if (!$video || !$subtitle) { echo json_encode(['error' => 'video and subtitle paths required']); break; }
            $id = DB::addToQueue($video, $subtitle, $title);
            echo json_encode(['status' => 'queued', 'queue_id' => $id]);
            break;

        case 'sync_batch':
            $input = json_decode(file_get_contents('php://input'), true);
            $folder = $input['folder'] ?? '';
            $recursive = (bool) ($input['recursive'] ?? false);
            $title = $input['title'] ?? '';
            if (!$folder) { echo json_encode(['error' => 'folder path required']); break; }
            $scan = SyncHelper::scanFolder($folder, $recursive);
            $queued = 0;
            foreach ($scan['pairs'] ?? [] as $pair) {
                foreach ($pair['subtitles'] as $sub) {
                    DB::addToQueue($pair['video'], $sub['path'], $title ?: basename($folder));
                    $queued++;
                }
            }
            echo json_encode(['status' => 'queued', 'count' => $queued]);
            break;

        case 'queue':
            echo json_encode(DB::getQueueStatus());
            break;

        case 'clear_queue':
            DB::clearQueue();
            echo json_encode(['status' => 'cleared']);
            break;

        case 'clear_failed':
            DB::clearFailedQueue();
            echo json_encode(['status' => 'cleared']);
            break;

        case 'clear_selected':
            $input = json_decode(file_get_contents('php://input'), true);
            $ids = $input['ids'] ?? [];
            if (!is_array($ids) || empty($ids)) {
                echo json_encode(['error' => 'No items selected']);
                break;
            }
            $count = DB::clearQueueItems($ids);
            echo json_encode(['status' => 'cleared', 'count' => $count]);
            break;

        case 'restore':
            $input = json_decode(file_get_contents('php://input'), true);
            $subtitle = $input['subtitle'] ?? '';
            if (!$subtitle) { echo json_encode(['error' => 'subtitle path required']); break; }
            echo json_encode(SyncHelper::restoreBackup($subtitle));
            break;

        case 'scrape':
            $scraper = getScraper();
            echo json_encode($scraper->scrapeAll());
            break;

        case 'test_connection':
            $scraper = getScraper();
            echo json_encode($scraper->testConnection());
            break;

        case 'detect_paths':
            $sourceType = DB::getSetting('source_type', 'kodi');
            $scraper = getScraper();
            $paths = $scraper->detectPaths();

            // Auto-save detected roots
            if ($paths['movies']) {
                DB::setSetting("{$sourceType}_movie_root", $paths['movies']['root']);
            }
            if ($paths['tv']) {
                DB::setSetting("{$sourceType}_tv_root", $paths['tv']['root']);
            }
            if ($paths['ok']) {
                DB::setSetting('paths_detected', '1');
            }
            echo json_encode($paths);
            break;

        case 'get_settings':
            $keys = ['source_type', 'kodi_host', 'kodi_port', 'kodi_user', 'kodi_pass',
                      'plex_host', 'plex_port', 'plex_token',
                      'emby_host', 'emby_port', 'emby_api_key',
                      'jellyfin_host', 'jellyfin_port', 'jellyfin_api_key',
                      'kodi_movie_root', 'kodi_tv_root',
                      'plex_movie_root', 'plex_tv_root',
                      'emby_movie_root', 'emby_tv_root',
                      'jellyfin_movie_root', 'jellyfin_tv_root',
                      'paths_detected',
                      'setup_complete', 'last_scrape', 'scrape_interval',
                      'sub_opensubtitles_enabled', 'sub_opensubtitles_api_key',
                      'sub_opensubtitles_username', 'sub_opensubtitles_password',
                      'sub_subdl_enabled', 'sub_subdl_api_key',
                      'sub_podnapisi_enabled',
                      'sub_addic7ed_enabled', 'sub_addic7ed_username', 'sub_addic7ed_password',
                      'sub_yify_enabled', 'sub_gestdown_enabled',
                      'sub_language'];
            $settings = [];
            foreach ($keys as $k) { $settings[$k] = DB::getSetting($k); }
            echo json_encode($settings);
            break;

        case 'save_settings':
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) { echo json_encode(['error' => 'Invalid JSON']); break; }
            $allowed = ['source_type', 'kodi_host', 'kodi_port', 'kodi_user', 'kodi_pass',
                         'plex_host', 'plex_port', 'plex_token',
                         'emby_host', 'emby_port', 'emby_api_key',
                         'jellyfin_host', 'jellyfin_port', 'jellyfin_api_key',
                         'kodi_movie_root', 'kodi_tv_root',
                         'plex_movie_root', 'plex_tv_root',
                         'emby_movie_root', 'emby_tv_root',
                         'jellyfin_movie_root', 'jellyfin_tv_root',
                         'setup_complete', 'scrape_interval',
                         'sub_opensubtitles_enabled', 'sub_opensubtitles_api_key',
                         'sub_opensubtitles_username', 'sub_opensubtitles_password',
                         'sub_subdl_enabled', 'sub_subdl_api_key',
                         'sub_podnapisi_enabled',
                         'sub_addic7ed_enabled', 'sub_addic7ed_username', 'sub_addic7ed_password',
                         'sub_yify_enabled', 'sub_gestdown_enabled',
                         'sub_language'];
            foreach ($input as $k => $v) {
                if (in_array($k, $allowed)) { DB::setSetting($k, (string) $v); }
            }
            echo json_encode(['status' => 'saved']);
            break;

        case 'stats':
            echo json_encode(DB::getStats());
            break;

        // ── Language Rules (Advanced) ──────────────────────────────────
        case 'save_language_rules':
            $input = json_decode(file_get_contents('php://input'), true);
            $rules = $input['rules'] ?? null;
            if (!$rules || !isset($rules['languages']) || !is_array($rules['languages']) || count($rules['languages']) === 0) {
                echo json_encode(['error' => 'At least one language is required']);
                break;
            }
            DB::setSetting('language_rules', json_encode($rules));
            // Re-evaluate any existing scan data against the new rules (fast — no ffprobe)
            $reevaluated = 0;
            try {
                require_once __DIR__ . '/includes/analyzer.php';
                $reevaluated = HealthScanner::reevaluateAll($rules);
            } catch (Throwable $t) {
                // non-fatal; rules still saved
            }
            echo json_encode(['status' => 'saved', 'rules' => $rules, 'reevaluated' => $reevaluated]);
            break;

        case 'get_language_rules':
            $raw = DB::getSetting('language_rules', '');
            if (empty($raw)) {
                echo json_encode(['rules' => null]);
            } else {
                $rules = json_decode($raw, true);
                echo json_encode(['rules' => $rules]);
            }
            break;

        // ── Analyzer test: evaluate a few titles and show verdicts ─────
        case 'analyze_sample':
            $raw = DB::getSetting('language_rules', '');
            if (empty($raw)) {
                echo json_encode(['error' => 'Set up your language preferences first']);
                break;
            }
            $rules = json_decode($raw, true);
            require_once __DIR__ . '/includes/analyzer.php';

            $type = $_GET['type'] ?? 'movie';
            $limit = min(50, max(1, (int) ($_GET['limit'] ?? 10)));
            $db = DB::get();

            $samples = [];
            if ($type === 'movie') {
                $rows = $db->query("SELECT id, title, year, folder_path FROM movies WHERE folder_path IS NOT NULL ORDER BY RANDOM() LIMIT $limit");
                foreach ($rows as $row) {
                    // Find main video
                    $vid = null; $best = 0;
                    if (is_dir($row['folder_path'])) {
                        foreach (scandir($row['folder_path']) as $f) {
                            if ($f === '.' || $f === '..') continue;
                            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                            if (!in_array($ext, ['mkv','mp4','avi','m4v'])) continue;
                            $sz = @filesize($row['folder_path'].'/'.$f);
                            if ($sz > $best) { $best = $sz; $vid = $row['folder_path'].'/'.$f; }
                        }
                    }
                    $verdict = $vid ? HealthScanner::evaluate($vid, $row['folder_path'], $rules)
                                    : ['verdict' => 'needs_input', 'reason' => 'No video file found.', 'audio_langs' => '', 'embedded_subs' => '[]', 'external_subs' => '[]', 'required_subs' => '', 'missing_subs' => ''];
                    $samples[] = [
                        'title' => $row['title'] . ($row['year'] ? " ({$row['year']})" : ''),
                        'folder' => $row['folder_path'],
                        'result' => $verdict,
                    ];
                }
            }
            echo json_encode(['ok' => true, 'samples' => $samples]);
            break;

        // ── Test Subtitle Provider ──────────────────────────────────────
        case 'test_provider':
            $input = json_decode(file_get_contents('php://input'), true);
            $provider = $input['provider'] ?? '';

            $providers = [
                'opensubtitles' => [
                    'api_key' => DB::getSetting('sub_opensubtitles_api_key', ''),
                    'username' => DB::getSetting('sub_opensubtitles_username', ''),
                    'password' => DB::getSetting('sub_opensubtitles_password', ''),
                ],
                'subdl' => [
                    'api_key' => DB::getSetting('sub_subdl_api_key', ''),
                ],
                'podnapisi' => [],
                'addic7ed' => [
                    'username' => DB::getSetting('sub_addic7ed_username', ''),
                    'password' => DB::getSetting('sub_addic7ed_password', ''),
                ],
                'yify' => [],
                'gestdown' => [],
            ];

            $args = json_encode([
                'provider' => $provider,
                'providers' => $providers,
            ]);

            $cmd = '/opt/ffsubsync/bin/python3 /app/subtitle_search.py test '
                 . escapeshellarg($args) . ' 2>&1';
            $output = shell_exec($cmd);
            $result = decodeJsonTolerant($output);
            echo json_encode($result ?: ['ok' => false, 'error' => 'Test failed', 'raw' => $output]);
            break;

        // ── Subtitle Search ────────────────────────────────────────────
        case 'subtitle_search':
            $input = json_decode(file_get_contents('php://input'), true);
            $title = $input['title'] ?? '';
            $year = $input['year'] ?? null;
            $imdbId = $input['imdb_id'] ?? '';
            $language = $input['language'] ?? DB::getSetting('sub_language', 'en');
            $videoPath = $input['video_path'] ?? '';
            $season = $input['season'] ?? null;
            $episode = $input['episode'] ?? null;

            if (!$title && !$imdbId) {
                echo json_encode(['error' => 'title or imdb_id required']);
                break;
            }

            // Build provider config from settings
            $providers = [
                'opensubtitles' => [
                    'enabled' => DB::getSetting('sub_opensubtitles_enabled', '0') === '1',
                    'api_key' => DB::getSetting('sub_opensubtitles_api_key', ''),
                    'username' => DB::getSetting('sub_opensubtitles_username', ''),
                    'password' => DB::getSetting('sub_opensubtitles_password', ''),
                ],
                'subdl' => [
                    'enabled' => DB::getSetting('sub_subdl_enabled', '0') === '1',
                    'api_key' => DB::getSetting('sub_subdl_api_key', ''),
                ],
                'podnapisi' => [
                    'enabled' => DB::getSetting('sub_podnapisi_enabled', '0') === '1',
                ],
                'addic7ed' => [
                    'enabled' => DB::getSetting('sub_addic7ed_enabled', '0') === '1',
                    'username' => DB::getSetting('sub_addic7ed_username', ''),
                    'password' => DB::getSetting('sub_addic7ed_password', ''),
                ],
                'yify' => [
                    'enabled' => DB::getSetting('sub_yify_enabled', '0') === '1',
                ],
                'gestdown' => [
                    'enabled' => DB::getSetting('sub_gestdown_enabled', '0') === '1',
                ],
            ];

            $args = json_encode([
                'title' => $title,
                'year' => $year,
                'imdb_id' => $imdbId,
                'language' => $language,
                'video_path' => $videoPath,
                'season' => $season,
                'episode' => $episode,
                'providers' => $providers,
            ]);

            $cmd = '/opt/ffsubsync/bin/python3 /app/subtitle_search.py search '
                 . escapeshellarg($args) . ' 2>&1';
            $output = shell_exec($cmd);
            $result = decodeJsonTolerant($output);

            if ($result === null) {
                echo json_encode(['error' => 'Search failed', 'raw' => $output]);
            } else {
                echo json_encode($result);
            }
            break;

        // ── Subtitle Download ──────────────────────────────────────────
        // ── Subtitle Preview (downloads to temp, returns first dialogue lines) ─
        case 'subtitle_preview':
            $input = json_decode(file_get_contents('php://input'), true);
            $provider = $input['provider'] ?? '';
            $fileId = $input['file_id'] ?? '';
            if (!$provider || !$fileId) {
                echo json_encode(['ok' => false, 'error' => 'provider and file_id required']);
                break;
            }
            $providers = [
                'opensubtitles' => [
                    'api_key'  => DB::getSetting('sub_opensubtitles_api_key', ''),
                    'username' => DB::getSetting('sub_opensubtitles_username', ''),
                    'password' => DB::getSetting('sub_opensubtitles_password', ''),
                ],
                'subdl' => ['api_key' => DB::getSetting('sub_subdl_api_key', '')],
                'podnapisi' => [],
                'addic7ed' => [
                    'username' => DB::getSetting('sub_addic7ed_username', ''),
                    'password' => DB::getSetting('sub_addic7ed_password', ''),
                ],
                'yify' => [],
                'gestdown' => [],
            ];
            $args = json_encode([
                'provider' => $provider,
                'file_id' => $fileId,
                'providers' => $providers,
                'max_lines' => 30,
            ]);
            $cmd = '/opt/ffsubsync/bin/python3 /app/subtitle_search.py preview '
                 . escapeshellarg($args) . ' 2>&1';
            $output = shell_exec($cmd);
            $result = decodeJsonTolerant($output);
            echo json_encode($result ?: ['ok' => false, 'error' => 'Preview failed', 'raw' => $output]);
            break;

        case 'subtitle_download':
            $input = json_decode(file_get_contents('php://input'), true);
            $provider = $input['provider'] ?? '';
            $fileId = $input['file_id'] ?? '';
            $videoPath = $input['video_path'] ?? '';
            $autoSync = (bool) ($input['auto_sync'] ?? false);
            // Use the language that was actually searched, not the default setting
            $lang = $input['language'] ?? DB::getSetting('sub_language', 'en');

            if (!$provider || !$fileId || !$videoPath) {
                echo json_encode(['error' => 'provider, file_id, and video_path required']);
                break;
            }

            // Determine output path — save alongside the video with language code
            $videoDir = dirname($videoPath);
            $videoBase = pathinfo($videoPath, PATHINFO_FILENAME);
            $outputPath = "{$videoDir}/{$videoBase}.{$lang}.srt";

            // Build provider config
            $providers = [
                'opensubtitles' => [
                    'api_key' => DB::getSetting('sub_opensubtitles_api_key', ''),
                    'username' => DB::getSetting('sub_opensubtitles_username', ''),
                    'password' => DB::getSetting('sub_opensubtitles_password', ''),
                ],
                'subdl' => [
                    'api_key' => DB::getSetting('sub_subdl_api_key', ''),
                ],
                'podnapisi' => [],
                'addic7ed' => [
                    'username' => DB::getSetting('sub_addic7ed_username', ''),
                    'password' => DB::getSetting('sub_addic7ed_password', ''),
                ],
                'yify' => [],
                'gestdown' => [],
            ];

            $args = json_encode([
                'provider' => $provider,
                'file_id' => $fileId,
                'output_path' => $outputPath,
                'providers' => $providers,
            ]);

            $cmd = '/opt/ffsubsync/bin/python3 /app/subtitle_search.py download '
                 . escapeshellarg($args) . ' 2>&1';
            $output = shell_exec($cmd);
            $result = decodeJsonTolerant($output);

            if ($result === null) {
                echo json_encode(['error' => 'Download failed', 'raw' => $output]);
                break;
            }

            if ($result['ok'] && $autoSync) {
                // Queue a sync job for the downloaded subtitle
                $title = $input['title'] ?? basename($videoDir);
                $queueId = DB::addToQueue($videoPath, $outputPath, $title);
                $result['sync_queued'] = true;
                $result['queue_id'] = $queueId;
            }

            echo json_encode($result);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action: ' . $action]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
