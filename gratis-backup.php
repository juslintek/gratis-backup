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

require_once __DIR__ . '/autoload.php';

add_action('plugins_loaded', function () {
    if (defined('WP_CLI') && WP_CLI) {
        \WP_CLI::add_command('gratis-backup', \Gratis\Backup\CLI\BackupCommand::class);
    }

    // Auto-snapshot before plugin/theme updates
    \Gratis\Backup\PreUpdateSnapshot::init();
});
