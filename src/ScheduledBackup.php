<?php declare(strict_types=1);
namespace Gratis\Backup;

/**
 * Scheduled automatic backups via WP-Cron.
 * What UpdraftPlus charges $70/yr for (scheduled cloud backups). Free in Gratis.
 */
final class ScheduledBackup
{
    private const CRON_HOOK = 'gratis_backup_scheduled';

    public static function init(): void
    {
        add_action(self::CRON_HOOK, [__CLASS__, 'run']);

        $schedule = get_option('gratis_backup_schedule', 'daily');
        if ($schedule && !wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), $schedule, self::CRON_HOOK);
        }

        // Register weekly schedule if not exists
        add_filter('cron_schedules', function ($schedules) {
            if (!isset($schedules['weekly'])) {
                $schedules['weekly'] = ['interval' => 604800, 'display' => 'Once Weekly'];
            }
            return $schedules;
        });
    }

    public static function run(): void
    {
        $dest = new Destination\LocalFilesystem();
        if (!$dest->isAvailable()) return;

        $engine = new BackupEngine($dest);
        $type = get_option('gratis_backup_type', 'database');

        $sources = match ($type) {
            'full'     => [new Source\DatabaseSource(), new Source\DirectorySource(WP_CONTENT_DIR . '/uploads', 'uploads', ['gratis-backups/*'])],
            'uploads'  => [new Source\DirectorySource(WP_CONTENT_DIR . '/uploads', 'uploads', ['gratis-backups/*'])],
            default    => [new Source\DatabaseSource()],
        };

        foreach ($sources as $source) {
            $engine->run($source);
        }

        // Rotation: keep last N backups
        self::rotate((int) get_option('gratis_backup_keep', 7));
    }

    private static function rotate(int $keep): void
    {
        $dir = WP_CONTENT_DIR . '/gratis-backup-manifests';
        if (!is_dir($dir)) return;
        $files = glob($dir . '/*.json');
        if (count($files) <= $keep) return;
        sort($files);
        $toDelete = array_slice($files, 0, count($files) - $keep);
        foreach ($toDelete as $f) @unlink($f);
    }
}
