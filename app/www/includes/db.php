<?php
/**
 * SubSyncarr Database Helper
 * Manages SQLite connection and common queries.
 */

class DB {
    private static ?PDO $instance = null;
    private const DB_PATH = '/config/subsync.db';

    public static function get(): PDO {
        if (self::$instance === null) {
            self::$instance = new PDO('sqlite:' . self::DB_PATH, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$instance->exec('PRAGMA journal_mode=WAL');
            self::$instance->exec('PRAGMA foreign_keys=ON');
        }
        return self::$instance;
    }

    public static function getSetting(string $key, string $default = ''): string {
        $stmt = self::get()->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? $row['value'] : $default;
    }

    public static function setSetting(string $key, string $value): void {
        $stmt = self::get()->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)');
        $stmt->execute([$key, $value]);
    }

    public static function searchMovies(string $query, int $limit = 20): array {
        $stmt = self::get()->prepare(
            'SELECT id, title, year, poster_url, folder_path, rating, genre, imdb_id, plot
             FROM movies WHERE title LIKE ? ORDER BY title ASC LIMIT ?'
        );
        $stmt->execute(["%{$query}%", $limit]);
        return $stmt->fetchAll();
    }

    public static function searchTVShows(string $query, int $limit = 20): array {
        $stmt = self::get()->prepare(
            'SELECT id, title, year, poster_url, seasons, episode_count, plot
             FROM tv_shows WHERE title LIKE ? ORDER BY title ASC LIMIT ?'
        );
        $stmt->execute(["%{$query}%", $limit]);
        return $stmt->fetchAll();
    }

    public static function getEpisodes(int $showId): array {
        $stmt = self::get()->prepare(
            'SELECT id, season, episode, title, file_path, folder_path
             FROM tv_episodes WHERE show_id = ? ORDER BY season, episode'
        );
        $stmt->execute([$showId]);
        return $stmt->fetchAll();
    }

    public static function getEpisodesBySeason(int $showId, int $season): array {
        $stmt = self::get()->prepare(
            'SELECT id, season, episode, title, file_path, folder_path
             FROM tv_episodes WHERE show_id = ? AND season = ? ORDER BY episode'
        );
        $stmt->execute([$showId, $season]);
        return $stmt->fetchAll();
    }

    public static function addToQueue(string $videoPath, string $subPath, string $title): int {
        $stmt = self::get()->prepare(
            'INSERT INTO sync_queue (video_path, subtitle_path, media_title, status)
             VALUES (?, ?, ?, \'pending\')'
        );
        $stmt->execute([$videoPath, $subPath, $title]);
        return (int) self::get()->lastInsertId();
    }

    public static function getQueueStatus(): array {
        $stmt = self::get()->query(
            'SELECT * FROM sync_queue ORDER BY
             CASE status WHEN \'running\' THEN 0 WHEN \'pending\' THEN 1 ELSE 2 END,
             created_at DESC LIMIT 50'
        );
        $items = $stmt->fetchAll();

        // Enrich with poster URLs by matching video paths
        foreach ($items as &$item) {
            $item['poster_url'] = '';
            $videoDir = dirname($item['video_path'] ?? '');
            if (!$videoDir || $videoDir === '.') continue;

            // Try movies first
            $ms = self::get()->prepare('SELECT poster_url FROM movies WHERE folder_path = ? LIMIT 1');
            $ms->execute([$videoDir]);
            $row = $ms->fetch();
            if ($row && $row['poster_url']) {
                $item['poster_url'] = $row['poster_url'];
                continue;
            }

            // Try TV episodes
            $es = self::get()->prepare(
                'SELECT ts.poster_url FROM tv_episodes te
                 JOIN tv_shows ts ON ts.id = te.show_id
                 WHERE te.folder_path = ? LIMIT 1'
            );
            $es->execute([$videoDir]);
            $row = $es->fetch();
            if ($row && $row['poster_url']) {
                $item['poster_url'] = $row['poster_url'];
            }
        }
        unset($item);
        return $items;
    }

    public static function getNextQueueItem(): ?array {
        $stmt = self::get()->prepare(
            'SELECT * FROM sync_queue WHERE status = \'pending\' ORDER BY created_at ASC LIMIT 1'
        );
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function updateQueueItem(int $id, string $status, string $log = '', ?string $startedAt = null, ?string $completedAt = null): void {
        $sql = 'UPDATE sync_queue SET status = ?, log = ?';
        $params = [$status, $log];
        if ($startedAt) { $sql .= ', started_at = ?'; $params[] = $startedAt; }
        if ($completedAt) { $sql .= ', completed_at = ?'; $params[] = $completedAt; }
        $sql .= ' WHERE id = ?';
        $params[] = $id;
        $stmt = self::get()->prepare($sql);
        $stmt->execute($params);
    }

    public static function addSyncHistory(string $type, string $title, string $video, string $sub, string $backup, string $status, string $log): void {
        $stmt = self::get()->prepare(
            'INSERT INTO sync_history (media_type, media_title, video_path, subtitle_path, backup_path, status, log, started_at, completed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, datetime(\'now\'), datetime(\'now\'))'
        );
        $stmt->execute([$type, $title, $video, $sub, $backup, $status, $log]);
    }

    public static function clearQueue(): void {
        self::get()->exec("DELETE FROM sync_queue");
    }

    public static function getStats(): array {
        $db = self::get();
        return [
            'movies' => (int) $db->query('SELECT COUNT(*) FROM movies')->fetchColumn(),
            'tv_shows' => (int) $db->query('SELECT COUNT(*) FROM tv_shows')->fetchColumn(),
            'tv_episodes' => (int) $db->query('SELECT COUNT(*) FROM tv_episodes')->fetchColumn(),
            'syncs_done' => (int) $db->query("SELECT COUNT(*) FROM sync_history WHERE status='success'")->fetchColumn(),
            'queue_pending' => (int) $db->query("SELECT COUNT(*) FROM sync_queue WHERE status='pending'")->fetchColumn(),
            'queue_running' => (int) $db->query("SELECT COUNT(*) FROM sync_queue WHERE status='running'")->fetchColumn(),
            'last_scrape' => self::getSetting('last_scrape', 'Never'),
        ];
    }
}
