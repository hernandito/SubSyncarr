<?php
/**
 * SubSyncarr Queue Worker
 * Runs as a persistent background process via supervisor.
 * Polls the sync_queue table and processes jobs one at a time.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/sync.php';

echo "[worker] SubSyncarr queue worker started.\n";

while (true) {
    try {
        $job = DB::getNextQueueItem();

        if ($job === null) {
            // No work — sleep 3 seconds and check again
            sleep(3);
            continue;
        }

        $id = (int) $job['id'];
        echo "[worker] Processing job #{$id}: {$job['media_title']}\n";

        // Mark as running
        DB::updateQueueItem($id, 'running', 'Starting sync...', date('Y-m-d H:i:s'));

        // Run the sync
        $result = SyncHelper::syncSubtitle($job['video_path'], $job['subtitle_path']);

        // Update queue with result
        DB::updateQueueItem(
            $id,
            $result['status'] === 'success' ? 'done' : 'failed',
            $result['log'],
            null,
            date('Y-m-d H:i:s')
        );

        // Also record in sync history
        DB::addSyncHistory(
            'sync',
            $job['media_title'],
            $job['video_path'],
            $job['subtitle_path'],
            $result['backup'] ?? '',
            $result['status'],
            $result['log']
        );

        $emoji = $result['status'] === 'success' ? '✓' : '✗';
        $elapsed = $result['elapsed'] ?? '?';
        echo "[worker] {$emoji} Job #{$id} {$result['status']} ({$elapsed}s)\n";

    } catch (Exception $e) {
        echo "[worker] ERROR: " . $e->getMessage() . "\n";
        // If we had a job in progress, mark it failed
        if (isset($id)) {
            try {
                DB::updateQueueItem($id, 'failed', 'Worker error: ' . $e->getMessage(), null, date('Y-m-d H:i:s'));
            } catch (Exception $e2) {}
        }
        sleep(5);
    }
}
