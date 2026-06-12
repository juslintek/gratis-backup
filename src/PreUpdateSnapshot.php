<?php declare(strict_types=1);
namespace Gratis\Backup;

/**
 * Hooks into WordPress update process to create automatic snapshots.
 */
final class PreUpdateSnapshot {

    public static function init(): void {
        // Before plugin/theme updates
        add_filter('upgrader_pre_install', [__CLASS__, 'beforeUpdate'], 10, 2);

        // After updates complete — log the event
        add_action('upgrader_process_complete', [__CLASS__, 'afterUpdate'], 10, 2);

        // Hook into cache-manager's pre-purge if available
        add_action('gratis_cache_purge_all', [__CLASS__, 'beforePurge']);
    }

    public static function beforeUpdate($response, array $hookExtra): mixed {
        $type = $hookExtra['type'] ?? 'unknown';
        $action = $hookExtra['action'] ?? 'unknown';

        if ($action !== 'update') return $response;

        // Create a database snapshot before updates
        try {
            $dest = new Destination\LocalFilesystem();
            if (!$dest->isAvailable()) return $response;

            $engine = new BackupEngine($dest);
            $source = new Source\DatabaseSource();
            $engine->run($source);

            if (function_exists('do_action')) {
                do_action('gratis_cache_event', 'backup.pre_update_snapshot', [
                    'type' => $type,
                    'trigger' => 'upgrader_pre_install',
                ]);
            }
        } catch (\Throwable $e) {
            // Never block updates due to backup failure; record the reason for observers.
            if (function_exists('do_action')) {
                do_action('gratis_backup_snapshot_failed', $e->getMessage());
            }
        }

        return $response;
    }

    public static function afterUpdate($upgrader, array $hookExtra): void {
        $type = $hookExtra['type'] ?? 'unknown';
        $plugins = $hookExtra['plugins'] ?? $hookExtra['themes'] ?? [];

        if (function_exists('do_action')) {
            do_action('gratis_cache_event', 'backup.update_completed', [
                'type' => $type,
                'items' => is_array($plugins) ? count($plugins) : 1,
            ]);
        }
    }

    public static function beforePurge(string $reason = ''): void {
        // Only snapshot on significant purges, not routine ones
        if ($reason === 'cli' || $reason === 'admin-manual') {
            return; // Don't snapshot on manual purges
        }
    }
}
