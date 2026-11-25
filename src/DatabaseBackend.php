<?php declare(strict_types=1);

namespace Gratis\Backup;

defined('ABSPATH') || exit;

// PDO with the sqlite driver is required for consistent `VACUUM INTO` copies and
// `PRAGMA integrity_check`; $wpdb cannot perform these file-level operations.
// phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO
// The removed PHP 5.4 "sqlite" (sqlite2) extension is unrelated to the modern
// pdo_sqlite driver and the 'sqlite' string literals used here, so the
// removed-extension compatibility sniff is a false positive in this file.
// phpcs:disable PHPCompatibility.Extensions.RemovedExtensions.sqliteRemoved

/**
 * Detects the active WordPress database backend and provides SQLite-aware
 * backup/restore primitives.
 *
 * WordPress can run on real MySQL/MariaDB or — via the
 * `sqlite-database-integration` db.php drop-in — on SQLite. The drop-in defines
 * DB_ENGINE / DATABASE_TYPE / FQDB / FQDBDIR (see its constants.php). We mirror
 * that resolution so a SQLite site is backed up by copying its single database
 * file consistently rather than shelling out to mysqldump (which does not exist
 * on a SQLite host).
 */
final class DatabaseBackend {

    public const ENGINE_MYSQL  = 'mysql';
    public const ENGINE_SQLITE = 'sqlite';

    /** Detect the active backend. Returns one of the ENGINE_* constants. */
    public static function detect(): string {
        if (defined('DB_ENGINE') && 'sqlite' === strtolower((string) constant('DB_ENGINE'))) {
            return self::ENGINE_SQLITE;
        }
        if (defined('DATABASE_TYPE') && 'sqlite' === strtolower((string) constant('DATABASE_TYPE'))) {
            return self::ENGINE_SQLITE;
        }
        // Only the SQLite integration drop-in defines FQDB.
        if (defined('FQDB')) {
            return self::ENGINE_SQLITE;
        }
        // Runtime fallback: the active $wpdb implementation is a SQLite subclass.
        if (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb'])
            && str_contains(strtolower(get_class($GLOBALS['wpdb'])), 'sqlite')) {
            return self::ENGINE_SQLITE;
        }
        // Last resort: a db.php drop-in mentioning sqlite plus a resolvable db file.
        $dropin = WP_CONTENT_DIR . '/db.php';
        if (is_file($dropin)) {
            // Reading the local wp-content/db.php drop-in, not a remote URL.
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $content = (string) @file_get_contents($dropin);
            if (str_contains(strtolower($content), 'sqlite') && is_file(self::sqliteFile())) {
                return self::ENGINE_SQLITE;
            }
        }
        return self::ENGINE_MYSQL;
    }

    public static function isSqlite(): bool {
        return self::ENGINE_SQLITE === self::detect();
    }

    /**
     * Resolve the absolute path to the SQLite database file, mirroring the
     * sqlite-database-integration constants.php resolution order.
     */
    public static function sqliteFile(): string {
        if (defined('FQDB')) {
            return (string) constant('FQDB');
        }
        if (defined('DB_DIR')) {
            $dir = rtrim((string) constant('DB_DIR'), '/\\') . '/';
        } elseif (defined('FQDBDIR')) {
            $dir = (string) constant('FQDBDIR');
        } else {
            $dir = WP_CONTENT_DIR . '/database/';
        }
        $file = defined('DB_FILE') ? (string) constant('DB_FILE') : '.ht.sqlite';
        return rtrim($dir, '/\\') . '/' . ltrim($file, '/\\');
    }

    /** Whether a PDO SQLite driver is available for consistent copies/integrity checks. */
    public static function hasPdoSqlite(): bool {
        return class_exists('PDO') && in_array('sqlite', \PDO::getAvailableDrivers(), true);
    }

    /**
     * Make a consistent point-in-time copy of a SQLite database to $target.
     *
     * Prefers `VACUUM INTO` (atomic, WAL-safe, produces a clean single file).
     * Falls back to a WAL-checkpointed plain copy, and finally to a raw copy.
     *
     * @return bool True if $target was written.
     */
    public static function copySqliteConsistently(string $source, string $target): bool {
        if (!is_file($source)) {
            return false;
        }
        // VACUUM INTO refuses to overwrite an existing target.
        if (is_file($target)) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
            @unlink($target);
        }

        if (self::hasPdoSqlite()) {
            try {
                $pdo = new \PDO('sqlite:' . $source);
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                // Flush the WAL into the main file so the snapshot is complete.
                // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                @$pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
                $pdo->exec('VACUUM INTO ' . $pdo->quote($target));
                $pdo = null;
                if (is_file($target) && filesize($target) > 0) {
                    return true;
                }
            } catch (\Throwable $e) {
                unset($e); // Fall through to a plain copy.
            }
        }

        // Fallback: best-effort raw copy (may miss an un-checkpointed WAL under load).
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
        return copy($source, $target);
    }

    /**
     * Run `PRAGMA integrity_check` against a SQLite file.
     *
     * @return bool True if the database reports "ok". When no PDO SQLite driver
     *              is available the check is skipped and treated as passing.
     */
    public static function sqliteIntegrityOk(string $file): bool {
        if (!is_file($file)) {
            return false;
        }
        if (!self::hasPdoSqlite()) {
            return true; // Cannot verify without a driver; do not block.
        }
        try {
            $pdo = new \PDO('sqlite:' . $file);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $result = $pdo->query('PRAGMA integrity_check');
            $row = $result ? $result->fetchColumn() : false;
            $pdo = null;
            return 'ok' === $row;
        } catch (\Throwable $e) {
            unset($e);
            return false;
        }
    }

    /**
     * Split a WordPress DB_HOST value into host, optional TCP port, and optional
     * Unix socket path (handles plain hosts, host:port, host:/path.sock, and
     * bracketed IPv6 like [::1]:3306).
     *
     * @return array{host:string,port:?int,socket:?string}
     */
    public static function parseDbHost(string $raw): array {
        $raw = trim($raw);
        if ('' === $raw) {
            return ['host' => 'localhost', 'port' => null, 'socket' => null];
        }

        $host   = $raw;
        $port   = null;
        $socket = null;

        if (str_starts_with($raw, '[')) {
            $end = strpos($raw, ']');
            if (false !== $end) {
                $host = substr($raw, 1, $end - 1);
                $rest = substr($raw, $end + 1);
                if (str_starts_with($rest, ':')) {
                    $tail = substr($rest, 1);
                    if (ctype_digit($tail)) {
                        $port = (int) $tail;
                    } elseif ('' !== $tail) {
                        $socket = $tail;
                    }
                }
            }
        } else {
            $colon = strpos($raw, ':');
            if (false !== $colon) {
                $host = substr($raw, 0, $colon);
                $tail = substr($raw, $colon + 1);
                if (ctype_digit($tail)) {
                    $port = (int) $tail;
                } elseif ('' !== $tail) {
                    $socket = $tail;
                }
            }
        }

        return [
            'host'   => '' !== $host ? $host : 'localhost',
            'port'   => $port,
            'socket' => $socket,
        ];
    }
}
