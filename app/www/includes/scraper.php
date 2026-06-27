<?php
/**
 * SubSyncarr Library Scraper
 * Scrapes Kodi JSON-RPC API for movies and TV episodes with file paths.
 * Uses auto-detected Kodi roots mapped to /movies and /tv container volumes.
 */

require_once __DIR__ . '/db.php';

class KodiScraper {

    private string $host;
    private int $port;
    private string $user;
    private string $pass;
    private string $movieRoot;  // e.g. smb://192.168.0.201/Media/Movies/
    private string $tvRoot;     // e.g. smb://192.168.0.201/Media/TV2/

    public function __construct() {
        $this->host = DB::getSetting('kodi_host', '192.168.0.201');
        $this->port = (int) DB::getSetting('kodi_port', '8080');
        $this->user = DB::getSetting('kodi_user', 'kodi');
        $this->pass = DB::getSetting('kodi_pass', 'kodi');
        $this->movieRoot = DB::getSetting('kodi_movie_root', '');
        $this->tvRoot = DB::getSetting('kodi_tv_root', '');
    }

    /**
     * Make a JSON-RPC call to Kodi.
     */
    private function rpc(string $method, array $params = []): mixed {
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'id' => 1,
        ]);

        $url = "http://{$this->user}:{$this->pass}@{$this->host}:{$this->port}/jsonrpc";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $httpCode !== 200) {
            return null;
        }

        $data = json_decode($resp, true);
        return $data['result'] ?? null;
    }

    /**
     * Test connection to Kodi.
     */
    public function testConnection(): array {
        $result = $this->rpc('JSONRPC.Ping');
        if ($result === 'pong' || $result !== null) {
            return ['ok' => true, 'message' => "Connected to Kodi at {$this->host}:{$this->port}"];
        }
        return ['ok' => false, 'message' => "Cannot reach Kodi at {$this->host}:{$this->port}"];
    }

    /**
     * Detect the Kodi root paths for movies and TV shows.
     * Fetches a sample movie and TV episode path, finds the common root.
     */
    public function detectPaths(): array {
        $result = ['ok' => false, 'movies' => null, 'tv' => null];

        // Detect movie root
        $movieData = $this->rpc('VideoLibrary.GetMovies', [
            'properties' => ['file'],
            'limits' => ['start' => 0, 'end' => 5],
        ]);

        if ($movieData && isset($movieData['movies']) && count($movieData['movies']) > 0) {
            $paths = [];
            foreach ($movieData['movies'] as $m) {
                $path = $this->extractFirstPath($m['file'] ?? '');
                if ($path) $paths[] = $path;
            }
            if (count($paths) > 0) {
                $movieRoot = $this->findCommonRoot($paths);
                if ($movieRoot) {
                    $result['movies'] = [
                        'root' => $movieRoot,
                        'sample_path' => $paths[0],
                        'count' => $movieData['limits']['total'] ?? count($movieData['movies']),
                    ];
                }
            }
        }

        // Detect TV root — get one show, then one episode
        $tvData = $this->rpc('VideoLibrary.GetTVShows', [
            'properties' => ['title'],
            'limits' => ['start' => 0, 'end' => 3],
        ]);

        if ($tvData && isset($tvData['tvshows']) && count($tvData['tvshows']) > 0) {
            $tvPaths = [];
            foreach ($tvData['tvshows'] as $show) {
                $epData = $this->rpc('VideoLibrary.GetEpisodes', [
                    'tvshowid' => $show['tvshowid'],
                    'properties' => ['file'],
                    'limits' => ['start' => 0, 'end' => 1],
                ]);
                if ($epData && isset($epData['episodes'][0])) {
                    $path = $epData['episodes'][0]['file'] ?? '';
                    if ($path) $tvPaths[] = $path;
                }
                if (count($tvPaths) >= 3) break;
            }
            if (count($tvPaths) > 0) {
                $tvRoot = $this->findCommonTVRoot($tvPaths);
                if ($tvRoot) {
                    $result['tv'] = [
                        'root' => $tvRoot,
                        'sample_path' => $tvPaths[0],
                        'count' => $tvData['limits']['total'] ?? count($tvData['tvshows']),
                    ];
                }
            }
        }

        $result['ok'] = ($result['movies'] !== null || $result['tv'] !== null);
        return $result;
    }

    /**
     * Extract the first usable path from a Kodi file value.
     * Handles stack:// paths by taking the first file.
     */
    private function extractFirstPath(string $kodiPath): ?string {
        $kodiPath = trim($kodiPath);
        if ($kodiPath === '') return null;

        if (stripos($kodiPath, 'stack://') === 0) {
            $kodiPath = substr($kodiPath, 8);
            $comma = stripos($kodiPath, ' , ');
            if ($comma !== false) {
                $kodiPath = substr($kodiPath, 0, $comma);
            }
        }
        return $kodiPath ?: null;
    }

    /**
     * Find the common directory root from a set of movie file paths.
     * e.g. from paths like smb://IP/Media/Movies/Title/file.mkv
     * returns smb://IP/Media/Movies/
     */
    private function findCommonRoot(array $paths): ?string {
        if (empty($paths)) return null;

        // Get directory of each path (strip filename)
        $dirs = array_map(function($p) {
            $p = rawurldecode($p);
            // Get parent of the movie folder (go up two levels from the file)
            // smb://IP/Media/Movies/Title (Year)/file.mkv → smb://IP/Media/Movies/
            $parts = explode('/', $p);
            array_pop($parts); // remove filename
            array_pop($parts); // remove movie folder
            return implode('/', $parts) . '/';
        }, $paths);

        // Find common prefix
        $common = $dirs[0];
        foreach ($dirs as $d) {
            while (strpos($d, $common) !== 0 && strlen($common) > 0) {
                $common = substr($common, 0, strrpos(rtrim($common, '/'), '/') + 1);
            }
        }
        return $common ?: null;
    }

    /**
     * Find common root for TV episode paths.
     * smb://IP/Media/TV2/Show Name/Season 01/ep.mkv → smb://IP/Media/TV2/
     */
    private function findCommonTVRoot(array $paths): ?string {
        if (empty($paths)) return null;

        $dirs = array_map(function($p) {
            $p = rawurldecode($p);
            $parts = explode('/', $p);
            array_pop($parts); // remove filename
            array_pop($parts); // remove Season folder
            array_pop($parts); // remove Show folder
            return implode('/', $parts) . '/';
        }, $paths);

        $common = $dirs[0];
        foreach ($dirs as $d) {
            while (strpos($d, $common) !== 0 && strlen($common) > 0) {
                $common = substr($common, 0, strrpos(rtrim($common, '/'), '/') + 1);
            }
        }
        return $common ?: null;
    }

    /**
     * Convert a Kodi movie path to a container path using detected roots.
     * smb://IP/Media/Movies/Title (Year)/file.mkv → /movies/Title (Year)
     */
    private function moviePathToFolder(string $kodiPath): ?string {
        $kodiPath = $this->extractFirstPath($kodiPath);
        if (!$kodiPath || !$this->movieRoot) return null;

        $attempts = [
            rawurldecode($kodiPath),
            rawurldecode(rawurldecode($kodiPath)),
            $kodiPath,
        ];

        foreach ($attempts as $decoded) {
            if (stripos($decoded, $this->movieRoot) === 0) {
                $relative = substr($decoded, strlen($this->movieRoot));
                $relative = str_replace('\\', '/', $relative);
                $folder = dirname($relative);
                if ($folder === '' || $folder === '.') continue;
                if (preg_match('#(^|/)\.\.(/|$)#', $relative)) continue;
                $topFolder = explode('/', $folder)[0];
                return '/movies/' . $topFolder;
            }
        }

        return null;
    }

    /**
     * Convert a Kodi TV episode path to container paths.
     * smb://IP/Media/TV2/Show/Season 01/ep.mkv → file: /tv/Show/Season 01/ep.mkv, folder: /tv/Show/Season 01
     */
    private function tvPathToFile(string $kodiPath): ?string {
        if (!$kodiPath || !$this->tvRoot) return null;

        $attempts = [
            rawurldecode($kodiPath),
            rawurldecode(rawurldecode($kodiPath)),
            $kodiPath,
        ];

        foreach ($attempts as $decoded) {
            if (stripos($decoded, $this->tvRoot) === 0) {
                $relative = substr($decoded, strlen($this->tvRoot));
                $relative = str_replace('\\', '/', $relative);
                // Block actual directory traversal (/../) but allow dots in filenames
                if (preg_match('#(^|/)\.\.(/|$)#', $relative)) continue;
                return '/tv/' . $relative;
            }
        }

        return null;
    }

    /**
     * Build an image URL from Kodi artwork.
     */
    private function imageUrl(?string $thumb): string {
        if (empty($thumb)) return '';
        $encoded = urlencode($thumb);

        if (preg_match('/tmdb|thetvdb/i', $encoded)) {
            $url = str_replace('image%3A%2F%2F', '', $encoded);
            $url = str_replace('%253a%252f%252f', '://', $url);
            $url = str_replace('%252f', '/', $url);
            $url = str_replace('%2F', '', $url);
            return $url;
        }

        return "http://{$this->user}:{$this->pass}@{$this->host}:{$this->port}/image/" . $encoded;
    }

    /**
     * Scrape all movies from Kodi.
     */
    public function scrapeMovies(): array {
        if (!$this->movieRoot) {
            return ['ok' => false, 'count' => 0, 'message' => 'Movie root path not detected — run Detect Library Paths first'];
        }

        $result = $this->rpc('VideoLibrary.GetMovies', [
            'properties' => ['art', 'year', 'imdbnumber', 'rating', 'genre', 'thumbnail', 'file', 'plot', 'streamdetails'],
            'sort' => ['order' => 'ascending', 'method' => 'label', 'ignorearticle' => true],
        ]);

        if (!$result || !isset($result['movies'])) {
            return ['ok' => false, 'count' => 0, 'message' => 'No movies returned from Kodi'];
        }

        $db = DB::get();

        // Mark all existing as stale; upsert in place; sweep what wasn't refreshed at the end.
        $db->exec("UPDATE movies SET scraped_at = '__stale__'");

        $movieSelect = $db->prepare('SELECT id FROM movies WHERE title=? AND year=? LIMIT 1');
        $movieInsert = $db->prepare(
            "INSERT INTO movies (title, year, poster_url, folder_path, imdb_id, rating, genre, plot, scraped_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))"
        );
        $movieUpdate = $db->prepare(
            "UPDATE movies SET poster_url=?, folder_path=?, imdb_id=?, rating=?, genre=?, plot=?, scraped_at=datetime('now') WHERE id=?"
        );

        $count = 0;
        foreach ($result['movies'] as $movie) {
            $title = $movie['label'] ?? '';
            $title = str_replace(['"', '&'], ["'", 'and'], $title);
            $title = htmlspecialchars_decode($title, ENT_QUOTES);

            $year = (int) ($movie['year'] ?? 0);
            $poster = $this->imageUrl($movie['art']['poster'] ?? '');
            $folder = $this->moviePathToFolder($movie['file'] ?? '');
            $imdbId = $movie['imdbnumber'] ?? '';
            $rating = round((float) ($movie['rating'] ?? 0), 1);
            $genres = is_array($movie['genre'] ?? null)
                ? implode(', ', array_slice($movie['genre'], 0, 4))
                : '';

            $plot = $movie['plot'] ?? '';
            $plot = str_replace('"', "'", $plot);

            $movieSelect->execute([$title, $year]);
            $existingId = $movieSelect->fetchColumn();

            if ($existingId) {
                $movieUpdate->execute([$poster, $folder, $imdbId, $rating, $genres, $plot, $existingId]);
            } else {
                $movieInsert->execute([$title, $year, $poster, $folder, $imdbId, $rating, $genres, $plot]);
            }
            $count++;
        }

        // Sweep stale rows (movies no longer in the library)
        $db->exec("DELETE FROM movies WHERE scraped_at = '__stale__'");

        return ['ok' => true, 'count' => $count, 'message' => "{$count} movies scraped"];
    }

    /**
     * Extract audio and subtitle languages from Kodi streamdetails.
     * Returns [audio_langs_csv, subtitle_langs_csv] as comma-separated ISO codes.
     */
    private function extractStreamLangs(?array $streamdetails): array {
        if (!$streamdetails) return ['', ''];

        $audioLangs = [];
        $subLangs = [];

        foreach ($streamdetails['audio'] ?? [] as $audio) {
            $lang = trim($audio['language'] ?? '');
            if ($lang && $lang !== 'und' && !in_array($lang, $audioLangs)) {
                $audioLangs[] = $lang;
            }
        }

        foreach ($streamdetails['subtitle'] ?? [] as $sub) {
            $lang = trim($sub['language'] ?? '');
            if ($lang && $lang !== 'und' && !in_array($lang, $subLangs)) {
                $subLangs[] = $lang;
            }
        }

        return [implode(',', $audioLangs), implode(',', $subLangs)];
    }

    /**
     * Scrape all TV shows and their episodes from Kodi.
     */
    public function scrapeTVShows(): array {
        if (!$this->tvRoot) {
            return ['ok' => false, 'count' => 0, 'episodes' => 0, 'message' => 'TV root path not detected — run Detect Library Paths first'];
        }

        $result = $this->rpc('VideoLibrary.GetTVShows', [
            'properties' => ['title', 'art', 'year', 'episode', 'season', 'imdbnumber', 'plot'],
            'sort' => ['order' => 'ascending', 'method' => 'title', 'ignorearticle' => true],
        ]);

        if (!$result || !isset($result['tvshows'])) {
            return ['ok' => false, 'count' => 0, 'episodes' => 0, 'message' => 'No TV shows returned from Kodi'];
        }

        $db = DB::get();

        // Mark every existing row stale so we can identify deletions after the scrape.
        // Then upsert each show/episode in place — preserving IDs so scan_state stays valid.
        $db->exec("UPDATE tv_shows SET scraped_at = '__stale__'");
        $db->exec("UPDATE tv_episodes SET scraped_at = '__stale__'");

        // Upsert show by (title, year). On match, refresh the existing row's metadata
        // and keep its id. On miss, insert a new row.
        $showSelect = $db->prepare('SELECT id FROM tv_shows WHERE title = ? AND year = ? LIMIT 1');
        $showInsert = $db->prepare(
            "INSERT INTO tv_shows (title, year, poster_url, seasons, episode_count, plot, scraped_at)
             VALUES (?, ?, ?, ?, ?, ?, datetime('now'))"
        );
        $showUpdate = $db->prepare(
            "UPDATE tv_shows SET poster_url=?, seasons=?, episode_count=?, plot=?, scraped_at=datetime('now') WHERE id=?"
        );

        // Upsert episode by (show_id, season, episode). Path may have changed, refresh it
        // along with stream details — but the id stays stable.
        $epSelect = $db->prepare('SELECT id FROM tv_episodes WHERE show_id=? AND season=? AND episode=? LIMIT 1');
        $epInsert = $db->prepare(
            "INSERT INTO tv_episodes (show_id, season, episode, title, file_path, folder_path, scraped_at)
             VALUES (?, ?, ?, ?, ?, ?, datetime('now'))"
        );
        $epUpdate = $db->prepare(
            "UPDATE tv_episodes SET title=?, file_path=?, folder_path=?, scraped_at=datetime('now') WHERE id=?"
        );

        $showCount = 0;
        $epCount = 0;

        foreach ($result['tvshows'] as $show) {
            $kodiShowId = $show['tvshowid'];
            $title = $show['title'] ?? '';
            $title = str_replace(['"', '&'], ["'", 'and'], $title);
            $year = (int) ($show['year'] ?? 0);
            $poster = $this->imageUrl($show['art']['poster'] ?? '');
            $seasons = (int) ($show['season'] ?? 0);
            $episodeCount = (int) ($show['episode'] ?? 0);
            $plot = $show['plot'] ?? '';

            // Look up existing show
            $showSelect->execute([$title, $year]);
            $existingShowId = $showSelect->fetchColumn();

            if ($existingShowId) {
                $showUpdate->execute([$poster, $seasons, $episodeCount, $plot, $existingShowId]);
                $localShowId = (int) $existingShowId;
            } else {
                $showInsert->execute([$title, $year, $poster, $seasons, $episodeCount, $plot]);
                $localShowId = (int) $db->lastInsertId();
            }
            $showCount++;

            $epResult = $this->rpc('VideoLibrary.GetEpisodes', [
                'tvshowid' => $kodiShowId,
                'properties' => ['title', 'season', 'episode', 'file', 'streamdetails'],
                'sort' => ['order' => 'ascending', 'method' => 'episode'],
            ]);

            if ($epResult && isset($epResult['episodes'])) {
                foreach ($epResult['episodes'] as $ep) {
                    $epTitle = $ep['title'] ?? '';
                    $epSeason = (int) ($ep['season'] ?? 0);
                    $epNum = (int) ($ep['episode'] ?? 0);
                    $kodiFile = $ep['file'] ?? '';
                    $filePath = $this->tvPathToFile($kodiFile);
                    $folderPath = $filePath ? dirname($filePath) : null;

                    $epSelect->execute([$localShowId, $epSeason, $epNum]);
                    $existingEpId = $epSelect->fetchColumn();

                    if ($existingEpId) {
                        $epUpdate->execute([$epTitle, $filePath, $folderPath, $existingEpId]);
                    } else {
                        $epInsert->execute([$localShowId, $epSeason, $epNum, $epTitle, $filePath, $folderPath]);
                    }
                    $epCount++;
                }
            }
        }

        // Sweep stale rows — episodes/shows that no longer exist in the source library.
        $db->exec("DELETE FROM tv_episodes WHERE scraped_at = '__stale__'");
        $db->exec("DELETE FROM tv_shows WHERE scraped_at = '__stale__'");

        return [
            'ok' => true,
            'count' => $showCount,
            'episodes' => $epCount,
            'message' => "{$showCount} shows, {$epCount} episodes scraped",
        ];
    }

    /**
     * Full scrape: movies + TV.
     */
    public function scrapeAll(): array {
        $movies = $this->scrapeMovies();
        $tv = $this->scrapeTVShows();

        DB::setSetting('last_scrape', date('Y-m-d H:i:s'));

        return [
            'ok' => $movies['ok'] && $tv['ok'],
            'movies' => $movies,
            'tv' => $tv,
            'timestamp' => date('Y-m-d H:i:s'),
        ];
    }
}
