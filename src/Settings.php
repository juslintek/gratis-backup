<?php declare(strict_types=1);

namespace Gratis\Backup;

defined('ABSPATH') || exit;

/**
 * Admin settings screen for Gratis Backup.
 *
 * Exposes the scheduled-backup configuration (frequency, type, retention),
 * a "Back up now" action, a list of existing backups, and a one-click
 * download of a backup's database export.
 */
final class Settings {

    private const GROUP = 'gratis_backup_settings_group';
    private const PAGE  = 'gratis-backup';

    /** Register the Tools submenu page. */
    public static function register_menu(): void {
        add_management_page(
            __('Gratis Backup', 'gratis-backup'),
            __('Gratis Backup', 'gratis-backup'),
            'manage_options',
            self::PAGE,
            [__CLASS__, 'render_page']
        );
    }

    /** Register each option with its sanitisation callback. */
    public static function register_settings(): void {
        register_setting(self::GROUP, 'gratis_backup_schedule', ['type' => 'string', 'sanitize_callback' => [__CLASS__, 'sanitize_schedule'], 'default' => 'daily']);
        register_setting(self::GROUP, 'gratis_backup_type', ['type' => 'string', 'sanitize_callback' => [__CLASS__, 'sanitize_type'], 'default' => 'database']);
        register_setting(self::GROUP, 'gratis_backup_keep', ['type' => 'integer', 'sanitize_callback' => [__CLASS__, 'sanitize_keep'], 'default' => 7]);
    }

    /** Whitelist the schedule frequency. */
    public static function sanitize_schedule($value): string {
        $value = sanitize_key((string) $value);
        return in_array($value, ['hourly', 'twicedaily', 'daily', 'weekly'], true) ? $value : 'daily';
    }

    /** Whitelist the backup type. */
    public static function sanitize_type($value): string {
        $value = sanitize_key((string) $value);
        return in_array($value, ['database', 'uploads', 'full'], true) ? $value : 'database';
    }

    /** Clamp the retention count to a sensible range. */
    public static function sanitize_keep($value): int {
        $keep = absint($value);
        return max(1, min(365, $keep));
    }

    /** Register the admin-post handlers for run + download + restore actions. */
    public static function register_actions(): void {
        add_action('admin_post_gratis_backup_run', [__CLASS__, 'handle_run']);
        add_action('admin_post_gratis_backup_download', [__CLASS__, 'handle_download']);
        add_action('admin_post_gratis_backup_restore', [__CLASS__, 'handle_restore']);
    }

    /** Run an on-demand backup (nonce + capability gated). */
    public static function handle_run(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to run backups.', 'gratis-backup'));
        }
        check_admin_referer('gratis_backup_run');

        $type = self::sanitize_type(isset($_POST['type']) ? sanitize_key(wp_unslash($_POST['type'])) : 'database');

        $dest = new Destination\LocalFilesystem();
        $status = 'error';
        if ($dest->isAvailable()) {
            $engine  = new BackupEngine($dest);
            $sources = self::sources_for_type($type);
            foreach ($sources as $source) {
                $engine->run($source);
            }
            $status = 'done';
        }

        wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'gratis_backup_msg' => $status], admin_url('tools.php')));
        exit;
    }

    /** Stream a backup's database export as a download (nonce + capability gated). */
    public static function handle_download(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to download backups.', 'gratis-backup'));
        }
        check_admin_referer('gratis_backup_download');

        $id = isset($_GET['id']) ? sanitize_file_name(wp_unslash($_GET['id'])) : '';
        if ('' === $id) {
            wp_die(esc_html__('No backup specified.', 'gratis-backup'));
        }

        $base = WP_CONTENT_DIR . '/gratis-backups/backups/' . $id;
        $root = realpath(WP_CONTENT_DIR . '/gratis-backups/backups');

        // A database backup is either a MySQL dump (database.sql) or a SQLite
        // file copy (database.sqlite). Serve whichever is present.
        $candidates = [
            'database.sql'    => 'application/sql',
            'database.sqlite' => 'application/octet-stream',
        ];
        $real  = false;
        $ctype = 'application/octet-stream';
        $fname = $id . '-database';
        foreach ($candidates as $name => $type) {
            $try = realpath($base . '/' . $name);
            if (false !== $try && false !== $root && str_starts_with($try, $root . '/') && is_file($try)) {
                $real  = $try;
                $ctype = $type;
                $fname = $id . '-' . $name;
                break;
            }
        }
        if (false === $real) {
            wp_die(esc_html__('Backup file not found.', 'gratis-backup'));
        }

        nocache_headers();
        header('Content-Type: ' . $ctype);
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Content-Length: ' . filesize($real));

        // Streaming a local backup file to the browser; WP_Filesystem cannot stream output.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
        readfile($real);
        exit;
    }

    /**
     * Restore (or dry-run verify) a backup — capability + nonce gated, with an
     * explicit confirmation required for the destructive path.
     */
    public static function handle_restore(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to restore backups.', 'gratis-backup'));
        }
        check_admin_referer('gratis_backup_restore');

        $id        = isset($_POST['id']) ? sanitize_file_name(wp_unslash($_POST['id'])) : '';
        $dry_run   = isset($_POST['dry_run']) && '1' === sanitize_text_field(wp_unslash($_POST['dry_run']));
        $confirmed = isset($_POST['confirm']) && 'yes' === sanitize_text_field(wp_unslash($_POST['confirm']));

        if ('' === $id) {
            self::store_restore_report([__('No backup specified.', 'gratis-backup')]);
            self::redirect_restore('restore_invalid');
            return;
        }

        $engine = new RestoreEngine();

        if ($dry_run) {
            $plan = $engine->verify($id);
            self::store_restore_report(self::summarize_plan($plan));
            self::redirect_restore($plan['ok'] ? 'verified' : 'restore_invalid');
            return;
        }

        // Destructive path: require the explicit confirmation checkbox.
        if (!$confirmed) {
            self::store_restore_report([__('Tick the confirmation box to run a restore.', 'gratis-backup')]);
            self::redirect_restore('restore_unconfirmed');
            return;
        }

        try {
            $result = $engine->restore($id);
        } catch (\Throwable $e) {
            self::store_restore_report([$e->getMessage()]);
            self::redirect_restore('restore_failed');
            return;
        }

        if (empty($result['restored'])) {
            self::store_restore_report($result['errors'] ?? [__('Backup is not restorable.', 'gratis-backup')]);
            self::redirect_restore('restore_invalid');
            return;
        }

        self::store_restore_report(self::summarize_result($result));
        self::redirect_restore('restored');
    }

    /** Redirect back to the settings page with a status code. */
    private static function redirect_restore(string $code): void {
        wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'gratis_backup_msg' => $code], admin_url('tools.php')));
        exit;
    }

    /** Stash a short, per-user restore report for display after the redirect. */
    private static function store_restore_report(array $lines): void {
        $clean = array_map(static fn($line) => (string) $line, array_slice(array_values($lines), 0, 50));
        set_transient('gratis_backup_restore_report_' . get_current_user_id(), $clean, 120);
    }

    /** @return array<int,string> */
    private static function summarize_plan(array $plan): array {
        $lines = [];
        $lines[] = sprintf('Backup: %s (source %s, %s)', (string) $plan['id'], (string) $plan['source'], (string) $plan['created']);
        if ('database' === ($plan['kind'] ?? '')) {
            $lines[] = sprintf('Database restore — engine %s, target %s', (string) $plan['db_engine'], (string) ($plan['db']['target'] ?? ''));
        } elseif ('files' === ($plan['kind'] ?? '')) {
            $summary = $plan['summary'];
            $lines[] = sprintf('Target: %s', (string) $plan['target_root']);
            $lines[] = sprintf(
                '%d create, %d overwrite, %d unchanged (%d total)',
                (int) ($summary['create'] ?? 0),
                (int) ($summary['overwrite'] ?? 0),
                (int) ($summary['unchanged'] ?? 0),
                (int) ($summary['files'] ?? 0)
            );
        }
        foreach ((array) ($plan['warnings'] ?? []) as $warning) {
            $lines[] = 'Warning: ' . (string) $warning;
        }
        foreach ((array) ($plan['errors'] ?? []) as $error) {
            $lines[] = 'Error: ' . (string) $error;
        }
        return $lines;
    }

    /** @return array<int,string> */
    private static function summarize_result(array $result): array {
        $plan  = $result['plan'];
        $lines = [__('Restore complete.', 'gratis-backup')];
        $lines[] = 'Pre-restore snapshot: ' . (string) ($result['snapshot_dir'] ?? 'n/a');
        if ('database' === ($plan['kind'] ?? '')) {
            $lines[] = sprintf('Database restored (%s).', (string) $plan['db_engine']);
        } else {
            $applied = (array) ($result['applied'] ?? []);
            $lines[] = sprintf(
                'Files: %d created, %d overwritten, %d unchanged, %d failed.',
                (int) ($applied['create'] ?? 0),
                (int) ($applied['overwrite'] ?? 0),
                (int) ($applied['unchanged'] ?? 0),
                (int) ($applied['failed'] ?? 0)
            );
        }
        foreach ((array) ($result['warnings'] ?? []) as $warning) {
            $lines[] = 'Warning: ' . (string) $warning;
        }
        return $lines;
    }

    /** Render the settings screen and backup list. */
    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'gratis-backup'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag for display only.
        $msg = isset($_GET['gratis_backup_msg']) ? sanitize_key(wp_unslash($_GET['gratis_backup_msg'])) : '';

        $schedule = (string) get_option('gratis_backup_schedule', 'daily');
        $type     = (string) get_option('gratis_backup_type', 'database');
        $keep     = (int) get_option('gratis_backup_keep', 7);

        $dest      = new Destination\LocalFilesystem();
        $writable  = $dest->isAvailable();
        $next      = wp_next_scheduled('gratis_backup_scheduled');

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Gratis Backup', 'gratis-backup') . '</h1>';
        echo '<p>' . esc_html__('Stream-first backups of your database and files, with scheduled runs and retention.', 'gratis-backup') . '</p>';

        if ('done' === $msg) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Backup completed.', 'gratis-backup') . '</p></div>';
        } elseif ('error' === $msg) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Backup failed — the backup directory is not writable.', 'gratis-backup') . '</p></div>';
        }

        self::render_restore_notice($msg);

        if (!$writable) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('The backup directory (wp-content/gratis-backups) is not writable.', 'gratis-backup') . '</p></div>';
        }

        // Settings form.
        echo '<form method="post" action="options.php">';
        settings_fields(self::GROUP);
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row"><label for="gb_schedule">' . esc_html__('Schedule', 'gratis-backup') . '</label></th><td>';
        echo '<select id="gb_schedule" name="gratis_backup_schedule">';
        $frequencies = [
            'hourly'     => __('Hourly', 'gratis-backup'),
            'twicedaily' => __('Twice daily', 'gratis-backup'),
            'daily'      => __('Daily', 'gratis-backup'),
            'weekly'     => __('Weekly', 'gratis-backup'),
        ];
        foreach ($frequencies as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($schedule, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        if ($next) {
            echo ' <span class="description">' . esc_html(sprintf(/* translators: %s: date/time. */ __('Next run: %s', 'gratis-backup'), wp_date('Y-m-d H:i', (int) $next))) . '</span>';
        }
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="gb_type">' . esc_html__('What to back up', 'gratis-backup') . '</label></th><td>';
        echo '<select id="gb_type" name="gratis_backup_type">';
        $types = [
            'database' => __('Database only', 'gratis-backup'),
            'uploads'  => __('Uploads only', 'gratis-backup'),
            'full'     => __('Database + uploads', 'gratis-backup'),
        ];
        foreach ($types as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($type, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></td></tr>';

        echo '<tr><th scope="row"><label for="gb_keep">' . esc_html__('Retention (backups to keep)', 'gratis-backup') . '</label></th><td>';
        echo '<input type="number" min="1" max="365" id="gb_keep" class="small-text" name="gratis_backup_keep" value="' . esc_attr((string) $keep) . '">';
        echo ' <span class="description">' . esc_html__('Older backups are pruned automatically.', 'gratis-backup') . '</span>';
        echo '</td></tr>';

        echo '</tbody></table>';
        submit_button();
        echo '</form>';

        // Run-now action.
        echo '<h2>' . esc_html__('Back up now', 'gratis-backup') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('gratis_backup_run');
        echo '<input type="hidden" name="action" value="gratis_backup_run">';
        echo '<input type="hidden" name="type" value="' . esc_attr($type) . '">';
        submit_button(__('Run backup now', 'gratis-backup'), 'secondary', 'submit', false);
        echo '</form>';

        self::render_backup_list();
        self::render_restore_panel();

        echo '</div>';
    }

    /** Render the table of existing backups with download links. */
    private static function render_backup_list(): void {
        $engine  = new BackupEngine(new Destination\LocalFilesystem());
        $backups = $engine->listBackups();

        echo '<h2>' . esc_html__('Existing backups', 'gratis-backup') . '</h2>';
        if (empty($backups)) {
            echo '<p>' . esc_html__('No backups yet.', 'gratis-backup') . '</p>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('ID', 'gratis-backup') . '</th>';
        echo '<th>' . esc_html__('Source', 'gratis-backup') . '</th>';
        echo '<th>' . esc_html__('Files', 'gratis-backup') . '</th>';
        echo '<th>' . esc_html__('Size', 'gratis-backup') . '</th>';
        echo '<th>' . esc_html__('Created', 'gratis-backup') . '</th>';
        echo '<th>' . esc_html__('Download', 'gratis-backup') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($backups as $b) {
            $id     = (string) ($b['id'] ?? '');
            $source = (string) ($b['source'] ?? '');
            $files  = absint($b['files'] ?? 0);
            $mb     = number_format_i18n(((int) ($b['bytes'] ?? 0)) / 1048576, 1);
            $created = (string) ($b['created'] ?? '');
            $is_db   = str_contains($id, 'database') || 'database' === $source;

            echo '<tr>';
            echo '<td><code>' . esc_html($id) . '</code></td>';
            echo '<td>' . esc_html($source) . '</td>';
            echo '<td>' . esc_html((string) $files) . '</td>';
            echo '<td>' . esc_html($mb) . ' MB</td>';
            echo '<td>' . esc_html($created) . '</td>';
            echo '<td>';
            if ($is_db && '' !== $id) {
                $url = wp_nonce_url(
                    add_query_arg(['action' => 'gratis_backup_download', 'id' => $id], admin_url('admin-post.php')),
                    'gratis_backup_download'
                );
                echo '<a class="button button-small" href="' . esc_url($url) . '">' . esc_html__('Download SQL', 'gratis-backup') . '</a>';
            } else {
                echo '<span class="description">&mdash;</span>';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /** Show the result of the most recent restore/verify action (if any). */
    private static function render_restore_notice(string $msg): void {
        $headlines = [
            'restored'            => [__('Restore complete.', 'gratis-backup'), 'notice-success'],
            'verified'            => [__('Dry run complete — no changes were made.', 'gratis-backup'), 'notice-info'],
            'restore_invalid'     => [__('Backup could not be restored.', 'gratis-backup'), 'notice-error'],
            'restore_failed'      => [__('Restore failed. Your data was protected by the pre-restore snapshot.', 'gratis-backup'), 'notice-error'],
            'restore_unconfirmed' => [__('Restore not run — confirmation required.', 'gratis-backup'), 'notice-warning'],
        ];
        if (!isset($headlines[$msg])) {
            return;
        }
        [$headline, $class] = $headlines[$msg];

        $key    = 'gratis_backup_restore_report_' . get_current_user_id();
        $report = get_transient($key);
        if (is_array($report)) {
            delete_transient($key);
        }

        echo '<div class="notice ' . esc_attr($class) . '"><p><strong>' . esc_html($headline) . '</strong></p>';
        if (is_array($report) && !empty($report)) {
            echo '<ul style="list-style:disc;margin-left:20px">';
            foreach ($report as $line) {
                echo '<li><code>' . esc_html((string) $line) . '</code></li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    /** Render the restore form: pick a backup, verify (dry run) or restore. */
    private static function render_restore_panel(): void {
        $engine  = new BackupEngine(new Destination\LocalFilesystem());
        $backups = $engine->listBackups();

        echo '<h2>' . esc_html__('Restore a backup', 'gratis-backup') . '</h2>';
        if (empty($backups)) {
            echo '<p>' . esc_html__('No backups available to restore.', 'gratis-backup') . '</p>';
            return;
        }

        echo '<div class="notice notice-warning inline"><p>' . esc_html__('Restoring overwrites current files or the database. A pre-restore snapshot is taken automatically and is never deleted. Use “Verify (dry run)” first.', 'gratis-backup') . '</p></div>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('gratis_backup_restore');
        echo '<input type="hidden" name="action" value="gratis_backup_restore">';
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row"><label for="gb_restore_id">' . esc_html__('Backup', 'gratis-backup') . '</label></th><td>';
        echo '<select id="gb_restore_id" name="id">';
        foreach ($backups as $b) {
            $bid = (string) ($b['id'] ?? '');
            if ('' === $bid) {
                continue;
            }
            $label = sprintf('%s — %s (%s)', $bid, (string) ($b['source'] ?? ''), (string) ($b['created'] ?? ''));
            echo '<option value="' . esc_attr($bid) . '">' . esc_html($label) . '</option>';
        }
        echo '</select></td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Confirmation', 'gratis-backup') . '</th><td><label><input type="checkbox" name="confirm" value="yes"> ' . esc_html__('I understand this overwrites current data.', 'gratis-backup') . '</label></td></tr>';

        echo '</tbody></table>';

        echo '<p>';
        echo '<button type="submit" name="dry_run" value="1" class="button button-secondary">' . esc_html__('Verify (dry run)', 'gratis-backup') . '</button> ';
        echo '<button type="submit" name="dry_run" value="0" class="button button-primary">' . esc_html__('Restore now', 'gratis-backup') . '</button>';
        echo '</p>';
        echo '</form>';
    }

    /** Build the source list for an on-demand backup of the given type. */
    private static function sources_for_type(string $type): array {
        return match ($type) {
            'uploads' => [new Source\DirectorySource(WP_CONTENT_DIR . '/uploads', 'uploads', ['gratis-backups/*'])],
            'full'    => [new Source\DatabaseSource(), new Source\DirectorySource(WP_CONTENT_DIR . '/uploads', 'uploads', ['gratis-backups/*'])],
            default   => [new Source\DatabaseSource()],
        };
    }
}
