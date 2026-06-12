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

    /** Register the admin-post handlers for run + download actions. */
    public static function register_actions(): void {
        add_action('admin_post_gratis_backup_run', [__CLASS__, 'handle_run']);
        add_action('admin_post_gratis_backup_download', [__CLASS__, 'handle_download']);
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
        $file = $base . '/database.sql';
        // Resolve the real path and confirm it stays inside the backup directory.
        $real = realpath($file);
        $root = realpath(WP_CONTENT_DIR . '/gratis-backups/backups');
        if (false === $real || false === $root || strncmp($real, $root, strlen($root)) !== 0 || !is_file($real)) {
            wp_die(esc_html__('Backup file not found.', 'gratis-backup'));
        }

        nocache_headers();
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $id . '-database.sql"');
        header('Content-Length: ' . filesize($real));

        // Streaming a local backup file to the browser; WP_Filesystem cannot stream output.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
        readfile($real);
        exit;
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

    /** Build the source list for an on-demand backup of the given type. */
    private static function sources_for_type(string $type): array {
        return match ($type) {
            'uploads' => [new Source\DirectorySource(WP_CONTENT_DIR . '/uploads', 'uploads', ['gratis-backups/*'])],
            'full'    => [new Source\DatabaseSource(), new Source\DirectorySource(WP_CONTENT_DIR . '/uploads', 'uploads', ['gratis-backups/*'])],
            default   => [new Source\DatabaseSource()],
        };
    }
}
