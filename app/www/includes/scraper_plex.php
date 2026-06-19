<?php
/**
 * SubSyncarr Plex Library Scraper
 * Scrapes Plex REST API for movies and TV episodes with file paths.
 * Uses X-Plex-Token authentication.
 */

require_once __DIR__ . '/db.php';

class PlexScraper {

    private string $host;
    private int $port;
    private string $token;
    private string $movieRoot;  // e.g. /data/Movies/
    private string $tvRoot;     // e.g. /data/TV Shows/

    public function __construct() {
        $this->host = DB::getSetting('plex_host', '');
        $this->port = (int) DB::getSetting('plex_port', '32400');
        $this->token = DB::getSetting('plex_token', '');
        $this->movieRoot = DB::getSetting('plex_movie_root', '');
        $this->tvRoot = DB::getSetting('plex_tv_root', '');
    }

    /**
     * Make a GET request to the Plex API.
     */
    private function api(string $endpoint): ?array {
        $url = "http://{$this->host}:{$this->port}{$endpoint}";
        $separator = strpos($url, '?') !== false ? '&' : '?';
        $url .= "{$separator}X-Plex-Token=" . urlencode($this->token);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
            ],
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
     * Test connection to Plex.
     */
    public function testConnection(): array {
        if (empty($this->host) || empty($this->token)) {
            return ['ok' => false, 'message' => 'Plex host and token are required'];
        }

        $data = $this->api('/');
        if ($data && isset($data['MediaContainer'])) {
            $name = $data['MediaContainer']['friendlyName'] ?? 'Plex Server';
            $version = $data['MediaContainer']['version'] ?? '';
            return ['ok' => true, 'message' => "Connected to {$name} (v{$version}) at {$this->host}:{$this->port}"];
        }
        return ['ok' => false, 'message' => "Cannot reach Plex at {$this->host}:{$this->port} — check host, port, and token"];
    }

    /**
     * Get all library sections (Movies, TV Shows, etc.)
     */
    private function getSections(): array {
        $data = $this->api('/library/sections');
        if (!$data || !isset($data['MediaContainer']['Directory'])) return [];
        return $data['MediaContainer']['Directory'];
    }

    /**
     * Find library sections by type.
     * 'movie' for movies, 'show' for TV shows.
     */
    private function findSectionsByType(string $type): array {
        $sections = $this->getSections();
        $matched = [];
        foreach ($sections as $section) {
            if (($section['type'] ?? '') === $type) {
                $matched[] = $section;
            }
        }
        return $matched;
    }

    /**
     * Detect Plex library paths.
     */
    public function detectPaths(): array {
        $result = ['ok' => false, 'movies' => null, 'tv' => null];

        // Find movie libraries
        $movieSections = $this->findSectionsByType('movie');
        if (!empty($movieSections)) {
            $section = $movieSections[0];
            $sectionId = $section['key'];

            // Get a sample movie to detect the path root
            $data = $this->api("/library/sections/{$sectionId}/all?X-Plex-Container-Start=0&X-Plex-Container-Size=3");
            if ($data && isset($data['MediaContainer']['Metadata'])) {
                $paths = [];
                foreach ($data['MediaContainer']['Metadata'] as $item) {
                    $filePath = $this->extractFilePath($item);
                    if ($filePath) $paths[] = $filePath;
                }
                if (!empty($paths)) {
                    $movieRoot = $this->findCommonMovieRoot($paths);
                    if ($movieRoot) {
                        $totalItems = $data['MediaContainer']['totalSize'] ?? count($data['MediaContainer']['Metadata']);
                        $result['movies'] = [
                            'root' => $movieRoot,
                            'sample_path' => $paths[0],
                            'count' => (int) $totalItems,
                            'section_id' => $sectionId,
                            'section_title' => $section['title'] ?? 'Movies',
                        ];
                    }
                }
            }
        }

        // Find TV libraries
        $tvSections = $this->findSectionsByType('show');
        if (!empty($tvSections)) {
            $section = $tvSections[0];
            $sectionId = $section['key'];

            // Get a sample show, then a sample episode
            $data = $this->api("/library/sections/{$sectionId}/all?X-Plex-Container-Start=0&X-Plex-Container-Size=3");
            if ($data && isset($data['MediaContainer']['Metadata'])) {
                $tvPaths = [];
                foreach ($data['MediaContainer']['Metadata'] as $show) {
                    $showKey = $show['ratingKey'] ?? '';
                    if (!$showKey) continue;

                    // Get first season
                    $seasonsData = $this->api("/library/metadata/{$showKey}/children");
                    if ($seasonsData && isset($seasonsData['MediaContainer']['Metadata'])) {
                        foreach ($seasonsData['MediaContainer']['Metadata'] as $season) {
                            $seasonKey = $season['ratingKey'] ?? '';
                            if (!$seasonKey) continue;

                            // Get first episode
                            $epsData = $this->api("/library/metadata/{$seasonKey}/children?X-Plex-Container-Size=1");
                            if ($epsData && isset($epsData['MediaContainer']['Metadata'][0])) {
                                $filePath = $this->extractFilePath($epsData['MediaContainer']['Metadata'][0]);
                                if ($filePath) {
                                    $tvPaths[] = $filePath;
                                    break 2; // Got one, that's enough for detection
                                }
                            }
                        }
                    }
                }

                if (!empty($tvPaths)) {
                    $tvRoot = $this->findCommonTVRoot($tvPaths);
                    if ($tvRoot) {
                        $totalItems = $data['MediaContainer']['totalSize'] ?? count($data['MediaContainer']['Metadata']);
                        $result['tv'] = [
                            'root' => $tvRoot,
                            'sample_path' => $tvPaths[0],
                            'count' => (int) $totalItems,
                            'section_id' => $sectionId,
                            'section_title' => $section['title'] ?? 'TV Shows',
                        ];
                    }
                }
            }
        }

        $result['ok'] = ($result['movies'] !== null || $result['tv'] !== null);
        return $result;
    }

    /**
     * Extract the file path from a Plex metadata item.
     */
    private function extractFilePath(array $item): ?string {
        if (isset($item['Media'][0]['Part'][0]['file'])) {
            return $item['Media'][0]['Part'][0]['file'];
        }
        return null;
    }

    /**
     * Extract poster URL from a Plex metadata item.
     */
    private function posterUrl(?string $thumb): string {
        if (empty($thumb)) return '';
        return "http://{$this->host}:{$this->port}{$thumb}?X-Plex-Token=" . urlencode($this->token);
    }

    /**
     * Find common movie root from file paths.
     * /data/Movies/Title (Year)/file.mkv → /data/Movies/
     */
    private function findCommonMovieRoot(array $paths): ?string {
        if (empty($paths)) return null;

        $dirs = array_map(function($p) {
            $parts = explode('/', $p);
            array_pop($parts); // remove filename
            array_pop($parts); // remove movie folder
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
     * /data/TV/Show Name/Season 01/ep.mkv → /data/TV/
     */
    private function findCommonTVRoot(array $paths): ?string {
        if (empty($paths)) return null;

        $dirs = array_map(function($p) {
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
     * Convert a Plex file path to SubSyncarr container path.
     * /data/Movies/Title (Year)/file.mkv → /movies/Title (Year)
     */
    private function moviePathToFolder(string $plexPath): ?string {
        if (!$this->movieRoot || !$plexPath) return null;
        if (strpos($plexPath, $this->movieRoot) !== 0) return null;

        $relative = substr($plexPath, strlen($this->movieRoot));
        if (strpos($relative, '..') !== false) return null;

        $topFolder = explode('/', $relative)[0];
        return '/movies/' . $topFolder;
    }

    /**
     * Convert a Plex episode path to SubSyncarr container path.
     * /data/TV/Show/Season 01/ep.mkv → /tv/Show/Season 01/ep.mkv
     */
    private function tvPathToFile(string $plexPath): ?string {
        if (!$this->tvRoot || !$plexPath) return null;
        if (strpos($plexPath, $this->tvRoot) !== 0) return null;

        $relative = substr($plexPath, strlen($this->tvRoot));
        if (strpos($relative, '..') !== false) return null;

        return '/tv/' . $relative;
    }

    /**
     * Scrape all movies from Plex.
     */
    public function scrapeMovies(): array {
        if (!$this->movieRoot) {
            return ['ok' => false, 'count' => 0, 'message' => 'Movie root path not detected — run Detect Library Paths first'];
        }

        $movieSections = $this->findSectionsByType('movie');
        if (empty($movieSections)) {
            return ['ok' => false, 'count' => 0, 'message' => 'No movie libraries found in Plex'];
        }

        $db = DB::get();
        $db->exec('DELETE FROM movies');

        $stmt = $db->prepare(
            'INSERT INTO movies (title, year, poster_url, folder_path, imdb_id, rating, genre, plot, scraped_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime(\'now\'))'
        );

        $count = 0;
        foreach ($movieSections as $section) {
            $sectionId = $section['key'];
            $data = $this->api("/library/sections/{$sectionId}/all");

            if (!$data || !isset($data['MediaContainer']['Metadata'])) continue;

            foreach ($data['MediaContainer']['Metadata'] as $movie) {
                $title = $movie['title'] ?? '';
                $year = (int) ($movie['year'] ?? 0);
                $poster = $this->posterUrl($movie['thumb'] ?? '');
                $filePath = $this->extractFilePath($movie);
                $folder = $filePath ? $this->moviePathToFolder($filePath) : null;
                $rating = round((float) ($movie['audienceRating'] ?? $movie['rating'] ?? 0), 1);
                $plot = $movie['summary'] ?? '';

                // Extract genres
                $genres = [];
                if (isset($movie['Genre']) && is_array($movie['Genre'])) {
                    foreach (array_slice($movie['Genre'], 0, 4) as $g) {
                        $genres[] = $g['tag'] ?? '';
                    }
                }
                $genreStr = implode(', ', array_filter($genres));

                // Extract IMDB ID from guids
                $imdbId = '';
                if (isset($movie['Guid']) && is_array($movie['Guid'])) {
                    foreach ($movie['Guid'] as $guid) {
                        $id = $guid['id'] ?? '';
                        if (strpos($id, 'imdb://') === 0) {
                            $imdbId = str_replace('imdb://', '', $id);
                            break;
                        }
                    }
                }

                $stmt->execute([$title, $year, $poster, $folder, $imdbId, $rating, $genreStr, $plot]);
                $count++;
            }
        }

        return ['ok' => true, 'count' => $count, 'message' => "{$count} movies scraped from Plex"];
    }

    /**
     * Scrape all TV shows and their episodes from Plex.
     */
    public function scrapeTVShows(): array {
        if (!$this->tvRoot) {
            return ['ok' => false, 'count' => 0, 'episodes' => 0, 'message' => 'TV root path not detected — run Detect Library Paths first'];
        }

        $tvSections = $this->findSectionsByType('show');
        if (empty($tvSections)) {
            return ['ok' => false, 'count' => 0, 'episodes' => 0, 'message' => 'No TV libraries found in Plex'];
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

        foreach ($tvSections as $section) {
            $sectionId = $section['key'];
            $data = $this->api("/library/sections/{$sectionId}/all");

            if (!$data || !isset($data['MediaContainer']['Metadata'])) continue;

            foreach ($data['MediaContainer']['Metadata'] as $show) {
                $title = $show['title'] ?? '';
                $year = (int) ($show['year'] ?? 0);
                $poster = $this->posterUrl($show['thumb'] ?? '');
                $plot = $show['summary'] ?? '';
                $leafCount = (int) ($show['leafCount'] ?? 0);
                $childCount = (int) ($show['childCount'] ?? 0);

                $showStmt->execute([$title, $year, $poster, $childCount, $leafCount, $plot]);
                $localShowId = (int) $db->lastInsertId();
                $showCount++;

                // Get seasons
                $showKey = $show['ratingKey'] ?? '';
                if (!$showKey) continue;

                $seasonsData = $this->api("/library/metadata/{$showKey}/children");
                if (!$seasonsData || !isset($seasonsData['MediaContainer']['Metadata'])) continue;

                foreach ($seasonsData['MediaContainer']['Metadata'] as $season) {
                    $seasonKey = $season['ratingKey'] ?? '';
                    $seasonNum = (int) ($season['index'] ?? 0);
                    if (!$seasonKey || $seasonNum === 0) continue; // Skip specials

                    // Get episodes
                    $epsData = $this->api("/library/metadata/{$seasonKey}/children");
                    if (!$epsData || !isset($epsData['MediaContainer']['Metadata'])) continue;

                    foreach ($epsData['MediaContainer']['Metadata'] as $ep) {
                        $epTitle = $ep['title'] ?? '';
                        $epNum = (int) ($ep['index'] ?? 0);
                        $filePath = $this->extractFilePath($ep);
                        $containerPath = $filePath ? $this->tvPathToFile($filePath) : null;
                        $folderPath = $containerPath ? dirname($containerPath) : null;

                        $epStmt->execute([$localShowId, $seasonNum, $epNum, $epTitle, $containerPath, $folderPath]);
                        $epCount++;
                    }
                }
            }
        }

        return [
            'ok' => true,
            'count' => $showCount,
            'episodes' => $epCount,
            'message' => "{$showCount} shows, {$epCount} episodes scraped from Plex",
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
