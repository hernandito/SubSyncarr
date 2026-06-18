<?php
/**
 * SubSyncarr Sync Helper
 * Scans media folders for video/subtitle files, detects embedded tracks,
 * and runs ffsubsync to fix external subtitle timing.
 * Uses /movies and /tv container mount points.
 */

class SyncHelper {

    private const VIDEO_EXTS = ['mkv', 'mp4', 'avi', 'm4v', 'ts', 'wmv'];
    private const SUB_EXTS = ['srt', 'ass', 'ssa', 'sub', 'vtt'];
    private const LANG_SUFFIXES = [
        '.en', '.eng', '.english', '.es', '.spa', '.spanish',
        '.fr', '.fre', '.french', '.de', '.ger', '.german',
        '.it', '.ita', '.italian', '.pt', '.por', '.portuguese',
        '.ru', '.rus', '.russian', '.zh', '.chi', '.chinese',
        '.ja', '.jpn', '.japanese', '.ko', '.kor', '.korean',
        '.forced', '.sdh', '.hi', '.cc',
    ];

    /**
     * Validate a path is safe (under /movies or /tv, no traversal).
     */
    public static function isSafePath(string $path): bool {
        $real = realpath(dirname($path));
        if ($real === false) {
            $real = realpath($path);
        }
        if ($real === false) return false;
        return strpos($real, '/movies') === 0 || strpos($real, '/tv') === 0;
    }

    /**
     * Scan a folder for video files, their external subs, and embedded tracks.
     */
    public static function scanFolder(string $folder, bool $recursive = false): array {
        if (!is_dir($folder)) {
            return ['error' => 'Folder not found: ' . $folder, 'folder' => $folder];
        }

        $videos = [];
        $allSubs = [];

        if ($recursive) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS)
            );
        } else {
            $iterator = new DirectoryIterator($folder);
        }

        foreach ($iterator as $file) {
            if ($file->isDir()) continue;
            $ext = strtolower($file->getExtension());
            $path = $file->getPathname();

            if (in_array($ext, self::VIDEO_EXTS)) {
                $videos[] = $path;
            } elseif (in_array($ext, self::SUB_EXTS)) {
                $allSubs[] = $path;
            }
        }

        sort($videos);
        sort($allSubs);

        $pairs = [];
        foreach ($videos as $vid) {
            $vidBase = pathinfo($vid, PATHINFO_FILENAME);
            $vidDir = dirname($vid);

            $matchedSubs = [];
            foreach ($allSubs as $sub) {
                if (dirname($sub) !== $vidDir) continue;

                $subBase = pathinfo($sub, PATHINFO_FILENAME);
                $subBaseStripped = $subBase;

                foreach (self::LANG_SUFFIXES as $suffix) {
                    if (strtolower(substr($subBaseStripped, -strlen($suffix))) === $suffix) {
                        $subBaseStripped = substr($subBaseStripped, 0, -strlen($suffix));
                        break;
                    }
                }

                if ($subBaseStripped === $vidBase || $subBase === $vidBase) {
                    $hasBackup = file_exists($sub . '.bak');
                    $matchedSubs[] = [
                        'path' => $sub,
                        'filename' => basename($sub),
                        'size_kb' => round(filesize($sub) / 1024, 1),
                        'has_backup' => $hasBackup,
                    ];
                }
            }

            $embedded = self::getEmbeddedTracks($vid);

            $pairs[] = [
                'video' => $vid,
                'video_filename' => basename($vid),
                'size_mb' => round(filesize($vid) / (1024 * 1024), 1),
                'subtitles' => $matchedSubs,
                'embedded_tracks' => $embedded,
            ];
        }

        return [
            'folder' => $folder,
            'pairs' => $pairs,
            'total_videos' => count($videos),
            'total_external_subs' => count($allSubs),
        ];
    }

    /**
     * Use ffprobe to detect embedded subtitle tracks in a video file.
     */
    public static function getEmbeddedTracks(string $videoPath): array {
        $cmd = sprintf(
            'ffprobe -v quiet -print_format json -show_streams -select_streams s %s 2>/dev/null',
            escapeshellarg($videoPath)
        );

        $output = shell_exec($cmd);
        if (!$output) return [];

        $data = json_decode($output, true);
        if (!$data || !isset($data['streams'])) return [];

        $tracks = [];
        foreach ($data['streams'] as $stream) {
            $lang = $stream['tags']['language'] ?? 'und';
            $codec = $stream['codec_name'] ?? 'unknown';
            $title = $stream['tags']['title'] ?? '';
            $forced = ($stream['disposition']['forced'] ?? 0) === 1;

            $tracks[] = [
                'index' => $stream['index'],
                'language' => strtoupper($lang),
                'codec' => strtoupper($codec),
                'title' => $title,
                'forced' => $forced,
            ];
        }
        return $tracks;
    }

    /**
     * Run ffsubsync on a video + subtitle pair.
     * Captures output progressively for live-ish log display.
     */
    public static function syncSubtitle(string $videoPath, string $subPath): array {
        $log = [];
        $log[] = 'Starting sync: ' . basename($subPath);
        $log[] = 'Against video: ' . basename($videoPath);

        if (!file_exists($videoPath)) {
            return ['status' => 'error', 'log' => implode("\n", $log) . "\nERROR: Video file not found"];
        }
        if (!file_exists($subPath)) {
            return ['status' => 'error', 'log' => implode("\n", $log) . "\nERROR: Subtitle file not found"];
        }

        // Backup
        $backupPath = $subPath . '.bak';
        $counter = 1;
        while (file_exists($backupPath)) {
            $backupPath = $subPath . ".bak{$counter}";
            $counter++;
        }
        copy($subPath, $backupPath);
        $log[] = 'Backup: ' . basename($backupPath);

        // Run ffsubsync
        // Output file MUST keep the original extension — ffsubsync determines format from it
        $ext = pathinfo($subPath, PATHINFO_EXTENSION);
        $baseName = substr($subPath, 0, -(strlen($ext) + 1));
        $syncedPath = $baseName . '.synced.' . $ext;
        $cmd = sprintf(
            'ffsubsync %s -i %s -o %s',
            escapeshellarg($videoPath),
            escapeshellarg($subPath),
            escapeshellarg($syncedPath)
        );

        $log[] = 'Running ffsubsync...';
        $startTime = microtime(true);

        // Run ffsubsync
        $output = [];
        $returnCode = 0;
        exec($cmd . ' 2>&1', $output, $returnCode);

        $elapsed = round(microtime(true) - $startTime, 1);

        // Add ffsubsync output to log (last 10 meaningful lines)
        $outputLines = array_filter($output, fn($l) => trim($l) !== '');
        $outputLines = array_slice($outputLines, -10);
        foreach ($outputLines as $line) {
            $log[] = '  ' . trim($line);
        }

        $log[] = "Completed in {$elapsed}s (exit code: {$returnCode})";

        // Success check: does the synced file exist and have content?
        // Some ffsubsync versions return non-zero even on success, so check the file.
        if (file_exists($syncedPath) && filesize($syncedPath) > 0) {
            rename($syncedPath, $subPath);
            $log[] = '✓ Subtitle synced and replaced successfully.';
            return [
                'status' => 'success',
                'log' => implode("\n", $log),
                'backup' => $backupPath,
                'elapsed' => $elapsed,
            ];
        } else {
            if (file_exists($syncedPath)) unlink($syncedPath);
            $log[] = '✗ Sync failed.';
            return [
                'status' => 'error',
                'log' => implode("\n", $log),
                'backup' => $backupPath,
                'elapsed' => $elapsed,
            ];
        }
    }

    /**
     * Restore a subtitle from its backup.
     */
    public static function restoreBackup(string $subPath): array {
        $backupPath = $subPath . '.bak';
        if (!file_exists($backupPath)) {
            return ['status' => 'error', 'message' => 'No backup found for this subtitle'];
        }
        copy($backupPath, $subPath);
        return ['status' => 'success', 'message' => 'Restored from ' . basename($backupPath)];
    }
}
