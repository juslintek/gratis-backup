<?php
/**
 * Plugin Name: Gratis Backup
 * Plugin URI: https://github.com/juslintek/gratis-backup
 * Description: Stream-first WordPress backup engine. Chunked, hashed, never loads full backups into memory.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: Linas Jusys
 * Author URI: https://github.com/juslintek
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: gratis-backup
 */

defined('ABSPATH') || exit;

define('GRATIS_BACKUP_VERSION', '1.0.0');
define('GRATIS_BACKUP_FILE', __FILE__);
define('GRATIS_BACKUP_DIR', plugin_dir_path(__FILE__));
define('GRATIS_BACKUP_URL', plugin_dir_url(__FILE__));

require_once __DIR__ . '/autoload.php';

add_action('plugins_loaded', function () {
    if (defined('WP_CLI') && WP_CLI) {
        \WP_CLI::add_command('gratis-backup', \Gratis\Backup\CLI\BackupCommand::class);
    }

    // Auto-snapshot before plugin/theme updates
    \Gratis\Backup\PreUpdateSnapshot::init();
    \Gratis\Backup\ScheduledBackup::init();
});

// Admin settings page + on-demand run/download actions.
add_action('admin_menu', [\Gratis\Backup\Settings::class, 'register_menu']);
add_action('admin_init', [\Gratis\Backup\Settings::class, 'register_settings']);
\Gratis\Backup\Settings::register_actions();

// Reschedule the cron event when the frequency changes.
add_action('update_option_gratis_backup_schedule', function ($old, $value) {
    if ($old === $value) {
        return;
    }
    $timestamp = wp_next_scheduled('gratis_backup_scheduled');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'gratis_backup_scheduled');
    }
    wp_schedule_event(time(), (string) $value, 'gratis_backup_scheduled');
}, 10, 2);

// Clean up the scheduled event on deactivation.
register_deactivation_hook(__FILE__, function () {
    $timestamp = wp_next_scheduled('gratis_backup_scheduled');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'gratis_backup_scheduled');
    }
});
