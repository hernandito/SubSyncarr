<?php
/**
 * SubSyncarr Emby Library Scraper
 * Scrapes Emby REST API for movies and TV episodes with file paths.
 * Uses API key authentication.
 *
 * Also serves as base for Jellyfin (nearly identical API, different URL prefix).
 */

require_once __DIR__ . '/db.php';

class EmbyScraper {

    private string $host;
    private int $port;
    private string $apiKey;
    private string $movieRoot;
    private string $tvRoot;
    private string $urlPrefix;    // '/emby' for Emby, '' for Jellyfin
    private string $settingPrefix; // 'emby' or 'jellyfin'
    private string $displayName;   // 'Emby' or 'Jellyfin'

    public function __construct(string $flavor = 'emby') {
        $this->settingPrefix = $flavor;
        $this->displayName = $flavor === 'jellyfin' ? 'Jellyfin' : 'Emby';
        $this->urlPrefix = $flavor === 'jellyfin' ? '' : '/emby';

        $this->host = DB::getSetting("{$flavor}_host", '');
        $this->port = (int) DB::getSetting("{$flavor}_port", '8096');
        $this->apiKey = DB::getSetting("{$flavor}_api_key", '');
        $this->movieRoot = DB::getSetting("{$flavor}_movie_root", '');
        $this->tvRoot = DB::getSetting("{$flavor}_tv_root", '');
    }

    /**
     * Make a GET request to the Emby/Jellyfin API.
     */
    private function api(string $endpoint, array $params = []): ?array {
        $params['api_key'] = $this->apiKey;
        $url = "http://{$this->host}:{$this->port}{$this->urlPrefix}{$endpoint}";
        $url .= '?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $httpCode !== 200) {
            return null;
        }

        return json_decode($resp, true);
    }

    /**
     * Test connection.
     */
    public function testConnection(): array {
        if (empty($this->host) || empty($this->apiKey)) {
            return ['ok' => false, 'message' => "{$this->displayName} host and API key are required"];
        }

        $data = $this->api('/System/Info');
        if ($data && isset($data['ServerName'])) {
            $name = $data['ServerName'];
            $version = $data['Version'] ?? '';
            return ['ok' => true, 'message' => "Connected to {$name} ({$this->displayName} v{$version}) at {$this->host}:{$this->port}"];
        }
        return ['ok' => false, 'message' => "Cannot reach {$this->displayName} at {$this->host}:{$this->port} — check host, port, and API key"];
    }

    /**
     * Detect library paths.
     */
    public function detectPaths(): array {
        $result = ['ok' => false, 'movies' => null, 'tv' => null];

        // Get a few movies to detect path root
        $data = $this->api('/Items', [
            'IncludeItemTypes' => 'Movie',
            'Recursive' => 'true',
            'Fields' => 'Path',
            'Limit' => 5,
            'SortBy' => 'SortName',
        ]);

        if ($data && isset($data['Items']) && count($data['Items']) > 0) {
            $paths = [];
            foreach ($data['Items'] as $item) {
                $path = $item['Path'] ?? '';
                if ($path) $paths[] = $path;
            }
            if (!empty($paths)) {
                $movieRoot = $this->findCommonMovieRoot($paths);
                if ($movieRoot) {
                    $result['movies'] = [
                        'root' => $movieRoot,
                        'sample_path' => $paths[0],
                        'count' => (int) ($data['TotalRecordCount'] ?? count($data['Items'])),
                        'section_title' => 'Movies',
                    ];
                }
            }
        }

        // Get a few TV episodes to detect path root
        $showData = $this->api('/Items', [
            'IncludeItemTypes' => 'Series',
            'Recursive' => 'true',
            'Limit' => 3,
            'SortBy' => 'SortName',
        ]);

        if ($showData && isset($showData['Items'])) {
            $tvPaths = [];
            foreach ($showData['Items'] as $show) {
                $showId = $show['Id'] ?? '';
                if (!$showId) continue;

                $epData = $this->api("/Shows/{$showId}/Episodes", [
                    'Fields' => 'Path',
                    'Limit' => 1,
                ]);

                if ($epData && isset($epData['Items'][0]['Path'])) {
                    $tvPaths[] = $epData['Items'][0]['Path'];
                    if (count($tvPaths) >= 2) break;
                }
            }

            if (!empty($tvPaths)) {
                $tvRoot = $this->findCommonTVRoot($tvPaths);
                if ($tvRoot) {
                    $result['tv'] = [
                        'root' => $tvRoot,
                        'sample_path' => $tvPaths[0],
                        'count' => (int) ($showData['TotalRecordCount'] ?? count($showData['Items'])),
                        'section_title' => 'TV Shows',
                    ];
                }
            }
        }

        $result['ok'] = ($result['movies'] !== null || $result['tv'] !== null);
        return $result;
    }

    /**
     * Find common movie root from file paths.
     */
    private function findCommonMovieRoot(array $paths): ?string {
        if (empty($paths)) return null;
        $dirs = array_map(function($p) {
            $parts = explode('/', $p);
            array_pop($parts); // filename
            array_pop($parts); // movie folder
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
     * Find common TV root from episode file paths.
     */
    private function findCommonTVRoot(array $paths): ?string {
        if (empty($paths)) return null;
        $dirs = array_map(function($p) {
            $parts = explode('/', $p);
            array_pop($parts); // filename
            array_pop($parts); // Season folder
            array_pop($parts); // Show folder
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
     * Convert path to container movie folder.
     */
    private function moviePathToFolder(string $path): ?string {
        if (!$this->movieRoot || !$path) return null;
        if (strpos($path, $this->movieRoot) !== 0) return null;
        $relative = substr($path, strlen($this->movieRoot));
        if (preg_match('#(^|/)\.\.(/|$)#', $relative)) return null;
        $topFolder = explode('/', $relative)[0];
        return '/movies/' . $topFolder;
    }

    /**
     * Convert path to container TV file path.
     */
    private function tvPathToFile(string $path): ?string {
        if (!$this->tvRoot || !$path) return null;
        if (strpos($path, $this->tvRoot) !== 0) return null;
        $relative = substr($path, strlen($this->tvRoot));
        if (preg_match('#(^|/)\.\.(/|$)#', $relative)) return null;
        return '/tv/' . $relative;
    }

    /**
     * Build poster URL.
     */
    private function posterUrl(string $itemId): string {
        if (!$itemId) return '';
        return "http://{$this->host}:{$this->port}{$this->urlPrefix}/Items/{$itemId}/Images/Primary?maxHeight=300&api_key=" . urlencode($this->apiKey);
    }

    /**
     * Scrape all movies.
     */
    public function scrapeMovies(): array {
        if (!$this->movieRoot) {
            return ['ok' => false, 'count' => 0, 'message' => 'Movie root path not detected — run Detect Library Paths first'];
        }

        $data = $this->api('/Items', [
            'IncludeItemTypes' => 'Movie',
            'Recursive' => 'true',
            'Fields' => 'Path,Overview,Genres,CommunityRating,ProviderIds',
            'SortBy' => 'SortName',
        ]);

        if (!$data || !isset($data['Items'])) {
            return ['ok' => false, 'count' => 0, 'message' => "No movies returned from {$this->displayName}"];
        }

        $db = DB::get();
        $db->exec('DELETE FROM movies');

        $stmt = $db->prepare(
            'INSERT INTO movies (title, year, poster_url, folder_path, imdb_id, rating, genre, plot, scraped_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime(\'now\'))'
        );

        $count = 0;
        foreach ($data['Items'] as $movie) {
            $title = $movie['Name'] ?? '';
            $year = (int) ($movie['ProductionYear'] ?? 0);
            $poster = $this->posterUrl($movie['Id'] ?? '');
            $folder = $this->moviePathToFolder($movie['Path'] ?? '');
            $rating = round((float) ($movie['CommunityRating'] ?? 0), 1);
            $plot = $movie['Overview'] ?? '';
            $imdbId = $movie['ProviderIds']['Imdb'] ?? '';
            $genres = implode(', ', array_slice($movie['Genres'] ?? [], 0, 4));

            $stmt->execute([$title, $year, $poster, $folder, $imdbId, $rating, $genres, $plot]);
            $count++;
        }

        return ['ok' => true, 'count' => $count, 'message' => "{$count} movies scraped from {$this->displayName}"];
    }

    /**
     * Scrape all TV shows and episodes.
     */
    public function scrapeTVShows(): array {
        if (!$this->tvRoot) {
            return ['ok' => false, 'count' => 0, 'episodes' => 0, 'message' => 'TV root path not detected — run Detect Library Paths first'];
        }

        $data = $this->api('/Items', [
            'IncludeItemTypes' => 'Series',
            'Recursive' => 'true',
            'Fields' => 'Overview,Genres,ProviderIds',
            'SortBy' => 'SortName',
        ]);

        if (!$data || !isset($data['Items'])) {
            return ['ok' => false, 'count' => 0, 'episodes' => 0, 'message' => "No TV shows returned from {$this->displayName}"];
        }

        $db = DB::get();
        $db->exec('DELETE FROM tv_episodes');
        $db->exec('DELETE FROM tv_shows');

        $showStmt = $db->prepare(
            'INSERT INTO tv_shows (title, year, poster_url, seasons, episode_count, plot, scraped_at)
             VALUES (?, ?, ?, ?, ?, ?, datetime(\'now\'))'
        );

        $epStmt = $db->prepare(
            'INSERT INTO tv_episodes (show_id, season, episode, title, file_path, folder_path, scraped_at)
             VALUES (?, ?, ?, ?, ?, ?, datetime(\'now\'))'
        );

        $showCount = 0;
        $epCount = 0;

        foreach ($data['Items'] as $show) {
            $showId = $show['Id'] ?? '';
            $title = $show['Name'] ?? '';
            $year = (int) ($show['ProductionYear'] ?? 0);
            $poster = $this->posterUrl($showId);
            $plot = $show['Overview'] ?? '';
            $childCount = (int) ($show['ChildCount'] ?? 0);

            // Get episode count and episodes
            $epData = $this->api("/Shows/{$showId}/Episodes", [
                'Fields' => 'Path',
            ]);

            $episodeCount = 0;
            $episodes = [];
            if ($epData && isset($epData['Items'])) {
                $episodes = $epData['Items'];
                $episodeCount = (int) ($epData['TotalRecordCount'] ?? count($episodes));
            }

            $showStmt->execute([$title, $year, $poster, $childCount, $episodeCount, $plot]);
            $localShowId = (int) $db->lastInsertId();
            $showCount++;

            foreach ($episodes as $ep) {
                $epTitle = $ep['Name'] ?? '';
                $epSeason = (int) ($ep['ParentIndexNumber'] ?? 0);
                $epNum = (int) ($ep['IndexNumber'] ?? 0);
                if ($epSeason === 0) continue; // Skip specials

                $filePath = $this->tvPathToFile($ep['Path'] ?? '');
                $folderPath = $filePath ? dirname($filePath) : null;

                $epStmt->execute([$localShowId, $epSeason, $epNum, $epTitle, $filePath, $folderPath]);
                $epCount++;
            }
        }

        return [
            'ok' => true,
            'count' => $showCount,
            'episodes' => $epCount,
            'message' => "{$showCount} shows, {$epCount} episodes scraped from {$this->displayName}",
        ];
    }

    /**
     * Full scrape.
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
