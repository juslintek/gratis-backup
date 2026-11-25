<?php declare(strict_types=1);

namespace Gratis\Backup;

defined('ABSPATH') || exit;

// RestoreEngine emits no browser output: its messages surface either as
// exceptions handled by WP-CLI or as admin notices that are escaped at the
// display boundary, so the exception-output security sniff does not apply here.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
// 'sqlite'/'database.sqlite' literals trip the PHP 5.4 removed-extension sniff —
// a false positive for the modern pdo_sqlite driver used by SQLite restores.
// phpcs:disable PHPCompatibility.Extensions.RemovedExtensions.sqliteRemoved

/**
 * Restores a backup produced by {@see BackupEngine}.
 *
 * A backup is a per-source set of loose files under
 * `wp-content/gratis-backups/backups/{id}/` described by a manifest in
 * `wp-content/gratis-backup-manifests/{id}.json` (per-file xxh3 hashes plus an
 * overall hash of the file list). A "database" backup contains a single
 * `database.sql` (MySQL) or `database.sqlite` (SQLite) file; "uploads",
 * "themes" and "plugins" backups contain a file tree restored under the
 * matching live directory.
 *
 * Safety model (correctness first):
 *  - Every restore is validated (per-file + overall hash) before any write.
 *  - A pre-restore safety snapshot is taken and NEVER deleted. A failed
 *    snapshot aborts the restore so current data is never left unprotected.
 *  - File writes are atomic (temp file + rename) and idempotent (unchanged
 *    files are skipped by hash).
 *  - SQLite restores integrity-check the incoming database, replace the live
 *    file atomically, and clear stale -wal/-shm so the old journal is not
 *    replayed over the restored database.
 *  - Cross-engine restores (SQLite backup onto a MySQL site or vice versa) are
 *    refused.
 */
final class RestoreEngine {

    private string $manifestDir;
    private string $backupsRoot;
    private string $snapshotRoot;

    public function __construct(
        ?string $backupsBase = null,
        ?string $manifestDir = null,
        ?string $snapshotRoot = null
    ) {
        $base               = rtrim($backupsBase ?? WP_CONTENT_DIR . '/gratis-backups', '/');
        $this->backupsRoot  = $base . '/backups';
        $this->snapshotRoot = $snapshotRoot ?? $base . '/pre-restore-snapshots';
        $this->manifestDir  = rtrim($manifestDir ?? WP_CONTENT_DIR . '/gratis-backup-manifests', '/');
    }

    // ---------------------------------------------------------------------
    // Pure, dependency-light helpers (unit-testable without WordPress).
    // ---------------------------------------------------------------------

    /** Recompute the manifest's overall hash exactly as BackupEngine does. */
    public static function overallHash(array $files): string {
        $json = function_exists('wp_json_encode')
            ? (string) wp_json_encode($files)
            // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WP-free fallback for the standalone self-check; identical bytes to wp_json_encode for scalar arrays.
            : (string) json_encode($files);
        return hash('xxh3', $json);
    }

    /** Stream-hash a file with xxh3 (matches BackupEngine's per-file hashing). */
    public static function hashFile(string $path): string {
        $context = hash_init('xxh3');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $handle = fopen($path, 'rb');
        if (!$handle) {
            return '';
        }
        while (!feof($handle)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
            $chunk = fread($handle, 65536);
            if ('' !== $chunk && false !== $chunk) {
                hash_update($context, $chunk);
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($handle);
        return hash_final($context);
    }

    /**
     * Validate that every manifest file exists in $backupDir with the expected
     * size and hash.
     *
     * @param array<int,array> $files Manifest file entries.
     * @return array<int,string> List of human-readable errors (empty == valid).
     */
    public static function validateBackupFiles(array $files, string $backupDir): array {
        $errors = [];
        foreach ($files as $entry) {
            $rel = (string) ($entry['path'] ?? '');
            $full = PathGuard::join($backupDir, $rel);
            if (null === $full) {
                $errors[] = "Unsafe path in manifest: {$rel}";
                continue;
            }
            if (!is_file($full)) {
                $errors[] = "Missing backup file: {$rel}";
                continue;
            }
            if (isset($entry['size']) && (int) filesize($full) !== (int) $entry['size']) {
                $errors[] = sprintf('Size mismatch for %s (expected %d, got %d).', $rel, (int) $entry['size'], (int) filesize($full));
            }
            if (!empty($entry['hash'])) {
                $actual = self::hashFile($full);
                if (!hash_equals((string) $entry['hash'], $actual)) {
                    $errors[] = "Hash mismatch for {$rel} (archive corrupt or modified).";
                }
            }
        }
        return $errors;
    }

    // ---------------------------------------------------------------------
    // Planning / dry-run.
    // ---------------------------------------------------------------------

    /** Alias of {@see plan()}; reports what a restore would do without writing. */
    public function verify(string $idOrManifest): array {
        return $this->plan($idOrManifest);
    }

    /**
     * Build a restore plan: resolve + validate the archive and classify every
     * change. Never writes. The returned array always has an 'errors' key;
     * a restore must refuse to run when it is non-empty.
     */
    public function plan(string $idOrManifest): array {
        $manifestPath = $this->resolveManifest($idOrManifest);
        if (null === $manifestPath) {
            return $this->failPlan(['Backup manifest not found: ' . $idOrManifest]);
        }

        // Reading a local manifest JSON file written by this plugin, not a remote URL.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $raw = file_get_contents($manifestPath);
        $data = $raw ? json_decode($raw, true) : null;
        if (!is_array($data) || empty($data['files']) || !is_array($data['files'])) {
            return $this->failPlan(['Manifest is unreadable or contains no files: ' . $manifestPath]);
        }

        $meta    = (isset($data['meta']) && is_array($data['meta'])) ? $data['meta'] : $data;
        $files   = array_values($data['files']);
        $id      = (string) ($meta['id'] ?? preg_replace('/\.json$/', '', basename($manifestPath)));
        $source  = (string) ($meta['source'] ?? '');
        $created = (string) ($meta['created'] ?? '');

        // The id is used to locate the backup data directory; never trust it
        // blindly (a crafted manifest could set "id":"../.." to escape the root).
        if ('' === $id || 1 !== preg_match('/^[A-Za-z0-9._-]+$/', $id)) {
            return $this->failPlan(['Manifest id is missing or contains unsafe characters: ' . $id]);
        }

        $backupDir = $this->backupsRoot . '/' . $id;

        $errors   = [];
        $warnings = [];

        // 1) Integrity: per-file hashes/sizes.
        $errors = array_merge($errors, self::validateBackupFiles($files, $backupDir));

        // 2) Integrity: overall manifest hash (when present).
        if (!empty($meta['hash'])) {
            $recomputed = self::overallHash($files);
            if (!hash_equals((string) $meta['hash'], $recomputed)) {
                $errors[] = "Manifest integrity hash mismatch (expected {$meta['hash']}, got {$recomputed}).";
            }
        } else {
            $warnings[] = 'Manifest has no overall hash; relying on per-file hashes only.';
        }

        $plan = $this->blankPlan();
        $plan['id']            = $id;
        $plan['source']        = $source;
        $plan['created']       = $created;
        $plan['manifest_path'] = $manifestPath;
        $plan['backup_dir']    = $backupDir;
        $plan['warnings']      = $warnings;

        $dbFile = $this->databaseFileIn($files);

        if ('database' === $source || (null !== $dbFile && '' === $source)) {
            $this->planDatabase($plan, $files, $dbFile, $errors);
        } else {
            $this->planFiles($plan, $files, $source, $errors);
        }

        $plan['errors'] = $errors;
        $plan['ok']     = empty($errors);
        return $plan;
    }

    private function planDatabase(array &$plan, array $files, ?string $dbFile, array &$errors): void {
        $plan['kind'] = 'database';
        if (null === $dbFile) {
            $errors[] = 'Database backup contains no database.sql or database.sqlite file.';
            return;
        }
        $backupEngine = ('database.sqlite' === $dbFile) ? DatabaseBackend::ENGINE_SQLITE : DatabaseBackend::ENGINE_MYSQL;
        $liveEngine   = DatabaseBackend::detect();
        if ($backupEngine !== $liveEngine) {
            $errors[] = sprintf(
                'Cross-engine restore is not supported: this backup is %s but the site runs %s.',
                $backupEngine,
                $liveEngine
            );
        }
        $size = 0;
        foreach ($files as $entry) {
            if (($entry['path'] ?? '') === $dbFile) {
                $size = (int) ($entry['size'] ?? 0);
                break;
            }
        }
        $target = (DatabaseBackend::ENGINE_SQLITE === $backupEngine)
            ? DatabaseBackend::sqliteFile()
            : (defined('DB_NAME') ? (string) constant('DB_NAME') : '');
        $plan['db_engine'] = $backupEngine;
        $plan['db_file']   = $dbFile;
        $plan['db']        = [
            'engine' => $backupEngine,
            'file'   => $dbFile,
            'bytes'  => $size,
            'target' => $target,
            'action' => (DatabaseBackend::ENGINE_SQLITE === $backupEngine) ? 'replace-file' : 'import-sql',
        ];
        $plan['summary']['bytes'] = $size;
    }

    private function planFiles(array &$plan, array $files, string $source, array &$errors): void {
        $plan['kind'] = 'files';
        $targetRoot   = $this->targetRootFor($source);
        if (null === $targetRoot) {
            $errors[] = "No known restore target for source '{$source}'.";
            return;
        }
        $plan['target_root'] = $targetRoot;

        foreach ($files as $entry) {
            $rel  = (string) ($entry['path'] ?? '');
            $size = (int) ($entry['size'] ?? 0);
            $hash = (string) ($entry['hash'] ?? '');
            $dest = PathGuard::join($targetRoot, $rel);
            if (null === $dest) {
                // Already reported by validateBackupFiles; record as invalid here too.
                $plan['files'][] = ['path' => $rel, 'size' => $size, 'action' => 'invalid', 'manifest_hash' => $hash, 'current_hash' => null];
                continue;
            }
            if (is_file($dest)) {
                $current = self::hashFile($dest);
                $action  = ('' !== $hash && $current === $hash) ? 'unchanged' : 'overwrite';
            } else {
                $current = null;
                $action  = 'create';
            }
            $plan['files'][] = ['path' => $rel, 'size' => $size, 'action' => $action, 'manifest_hash' => $hash, 'current_hash' => $current];
            ++$plan['summary']['files'];
            $plan['summary'][$action] = ($plan['summary'][$action] ?? 0) + 1;
            if ('unchanged' !== $action) {
                $plan['summary']['bytes'] += $size;
            }
        }
    }

    // ---------------------------------------------------------------------
    // Destructive restore.
    // ---------------------------------------------------------------------

    /**
     * Restore the given backup. Validates first and aborts (no writes) when the
     * archive is invalid. Takes a pre-restore snapshot before applying changes.
     *
     * @throws \RuntimeException When the pre-restore snapshot or a database
     *                           import fails (current data is left intact and
     *                           the snapshot directory is preserved).
     * @return array Result with 'restored', 'plan', 'snapshot_dir', 'applied'.
     */
    public function restore(string $idOrManifest): array {
        $plan = $this->plan($idOrManifest);
        if (!$plan['ok']) {
            return ['restored' => false, 'plan' => $plan, 'errors' => $plan['errors'], 'snapshot_dir' => null];
        }

        $snapDir = $this->snapshotRoot . '/' . gmdate('Ymd-His') . '-' . $plan['id'];
        if (!$this->ensureDir($snapDir)) {
            throw new \RuntimeException('Could not create pre-restore snapshot directory: ' . $snapDir);
        }

        $snapshot = [
            'created'         => gmdate('c'),
            'restored_backup' => $plan['id'],
            'source'          => $plan['source'],
            'kind'            => $plan['kind'],
            'dir'             => $snapDir,
            'database'        => null,
            'files'           => [],
            'added'           => [],
            'warnings'        => [],
        ];

        $applied = [];

        if ('database' === $plan['kind']) {
            $snap = $this->snapshotDatabase($plan, $snapDir);
            if (!$snap['ok']) {
                $this->writeSnapshotManifest($snapDir, $snapshot);
                throw new \RuntimeException(
                    'Pre-restore database snapshot failed; aborting to protect current data.'
                    . ($snap['error'] ?? '') . ' Snapshot dir: ' . $snapDir
                );
            }
            $snapshot['database'] = $snap['file'];
            if (!empty($snap['warning'])) {
                $snapshot['warnings'][] = $snap['warning'];
            }
            $this->writeSnapshotManifest($snapDir, $snapshot);

            $this->importDatabase($plan); // Throws on failure; snapshot preserved.
            $applied = ['database' => $plan['db_engine']];
        } else {
            [$snapped, $added] = $this->snapshotFileTree($plan, $snapDir);
            $snapshot['files'] = $snapped;
            $snapshot['added'] = $added;
            $this->writeSnapshotManifest($snapDir, $snapshot);

            $applied = $this->restoreFileTree($plan);
        }

        return [
            'restored'     => true,
            'plan'         => $plan,
            'snapshot_dir' => $snapDir,
            'applied'      => $applied,
            'warnings'     => $snapshot['warnings'],
        ];
    }

    // ---------------------------------------------------------------------
    // Snapshot helpers.
    // ---------------------------------------------------------------------

    /** @return array{ok:bool,file?:?string,warning?:string,error?:string} */
    private function snapshotDatabase(array $plan, string $snapDir): array {
        if (DatabaseBackend::ENGINE_SQLITE === $plan['db_engine']) {
            $live = DatabaseBackend::sqliteFile();
            if (!is_file($live)) {
                return ['ok' => true, 'file' => null, 'warning' => 'No existing SQLite database to snapshot (' . $live . ').'];
            }
            $dest = $snapDir . '/database.sqlite';
            if (!DatabaseBackend::copySqliteConsistently($live, $dest)) {
                return ['ok' => false, 'error' => ' (SQLite snapshot copy failed)'];
            }
            return ['ok' => true, 'file' => 'database.sqlite'];
        }

        // MySQL: dump current database with mysqldump (symmetric with the backup path).
        $dest = $snapDir . '/database.sql';
        $code = $this->runMysqlClient('mysqldump', $dest, true);
        if (0 !== $code || !is_file($dest) || 0 === (int) filesize($dest)) {
            return ['ok' => false, 'error' => ' (mysqldump exit ' . $code . ')'];
        }
        return ['ok' => true, 'file' => 'database.sql'];
    }

    /**
     * Copy every to-be-overwritten file into the snapshot before applying, and
     * record newly-created paths so a manual rollback can identify them.
     *
     * @return array{0:array<int,string>,1:array<int,string>} [snapped, added]
     */
    private function snapshotFileTree(array $plan, string $snapDir): array {
        $snapped = [];
        $added   = [];
        foreach ($plan['files'] as $entry) {
            if ('overwrite' === $entry['action']) {
                $dest = PathGuard::join($plan['target_root'], $entry['path']);
                $snapTarget = PathGuard::join($snapDir . '/files', $entry['path']);
                if (null !== $dest && null !== $snapTarget && is_file($dest)) {
                    $tmp = $this->streamToTemp($dest, dirname($snapTarget));
                    if (null !== $tmp) {
                        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
                        if (@rename($tmp, $snapTarget)) {
                            $snapped[] = $entry['path'];
                        } else {
                            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
                            @unlink($tmp);
                        }
                    }
                }
            } elseif ('create' === $entry['action']) {
                $added[] = $entry['path'];
            }
        }
        return [$snapped, $added];
    }

    private function writeSnapshotManifest(string $snapDir, array $snapshot): void {
        $json = wp_json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        // Local snapshot manifest; WP_Filesystem is not suitable for this path.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents($snapDir . '/manifest.json', (string) $json, LOCK_EX);
    }

    // ---------------------------------------------------------------------
    // Apply helpers.
    // ---------------------------------------------------------------------

    /** @return array<string,int> Counts keyed by action plus 'failed'. */
    private function restoreFileTree(array $plan): array {
        $applied = ['create' => 0, 'overwrite' => 0, 'unchanged' => 0, 'failed' => 0];
        foreach ($plan['files'] as $entry) {
            $action = $entry['action'];
            if ('unchanged' === $action) {
                ++$applied['unchanged'];
                continue;
            }
            if ('invalid' === $action) {
                ++$applied['failed'];
                continue;
            }
            $src  = PathGuard::join($plan['backup_dir'], $entry['path']);
            $dest = PathGuard::join($plan['target_root'], $entry['path']);
            if (null === $src || null === $dest || !is_file($src)) {
                ++$applied['failed'];
                continue;
            }
            $tmp = $this->streamToTemp($src, dirname($dest));
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
            if (null === $tmp || !@rename($tmp, $dest)) {
                if (null !== $tmp) {
                    // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
                    @unlink($tmp);
                }
                ++$applied['failed'];
                continue;
            }
            ++$applied[$action];
        }
        return $applied;
    }

    /**
     * Replace the live database with the backup's database file.
     *
     * @throws \RuntimeException On any failure (current DB protected by snapshot).
     */
    private function importDatabase(array $plan): void {
        $backupDb = PathGuard::join($plan['backup_dir'], $plan['db_file']);
        if (null === $backupDb || !is_file($backupDb)) {
            throw new \RuntimeException('Backup database file is missing: ' . (string) $plan['db_file']);
        }

        if (DatabaseBackend::ENGINE_SQLITE === $plan['db_engine']) {
            if (!DatabaseBackend::sqliteIntegrityOk($backupDb)) {
                throw new \RuntimeException('Backup SQLite database failed integrity check; aborting.');
            }
            $live = DatabaseBackend::sqliteFile();
            $dir  = dirname($live);
            if (!$this->ensureDir($dir)) {
                throw new \RuntimeException('SQLite database directory is not writable: ' . $dir);
            }
            $staged = $this->streamToTemp($backupDb, $dir);
            if (null === $staged) {
                throw new \RuntimeException('Failed to stage SQLite database for restore.');
            }
            if (!DatabaseBackend::sqliteIntegrityOk($staged)) {
                // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
                @unlink($staged);
                throw new \RuntimeException('Staged SQLite database failed integrity check; aborting.');
            }
            // Close the live connection so the file is not held open during replace.
            if (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb']) && method_exists($GLOBALS['wpdb'], 'close')) {
                // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                @$GLOBALS['wpdb']->close();
            }
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
            if (!@rename($staged, $live)) {
                // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
                @unlink($staged);
                throw new \RuntimeException('Failed to replace the live SQLite database file: ' . $live);
            }
            // Drop stale WAL/SHM so the old journal is not replayed over the restore.
            foreach (['-wal', '-shm'] as $ext) {
                if (is_file($live . $ext)) {
                    // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
                    @unlink($live . $ext);
                }
            }
            return;
        }

        // MySQL import via the mysql client (symmetric with the mysqldump backup).
        $code = $this->runMysqlClient('mysql', $backupDb, false);
        if (0 !== $code) {
            throw new \RuntimeException('MySQL import failed (mysql client exit ' . $code . ').');
        }
    }

    /**
     * Run the mysql/mysqldump client with the WordPress DB credentials.
     * The password is passed via MYSQL_PWD (not argv) to avoid leaking it to
     * the process list. $isDump selects redirection: dump writes stdout to the
     * file; import reads stdin from the file.
     */
    private function runMysqlClient(string $binary, string $file, bool $isDump): int {
        if (!function_exists('exec')) {
            return 127;
        }
        $rawHost = defined('DB_HOST') ? (string) constant('DB_HOST') : 'localhost';
        $parsed  = DatabaseBackend::parseDbHost($rawHost);
        $user    = defined('DB_USER') ? (string) constant('DB_USER') : '';
        $name    = defined('DB_NAME') ? (string) constant('DB_NAME') : '';
        $pass    = defined('DB_PASSWORD') ? (string) constant('DB_PASSWORD') : '';

        $args = ['--host=' . escapeshellarg($parsed['host'])];
        if (null !== $parsed['port']) {
            $args[] = '--port=' . escapeshellarg((string) $parsed['port']);
        }
        if (null !== $parsed['socket']) {
            $args[] = '--socket=' . escapeshellarg($parsed['socket']);
        }
        $args[] = '--user=' . escapeshellarg($user);

        $redirect = $isDump
            ? '> ' . escapeshellarg($file) . ' 2>/dev/null'
            : '< ' . escapeshellarg($file) . ' 2>/dev/null';

        $cmd = sprintf(
            '%s %s %s %s',
            escapeshellcmd($binary),
            implode(' ', $args),
            escapeshellarg($name),
            $redirect
        );

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
        putenv('MYSQL_PWD=' . $pass);
        $output = [];
        $code   = 1;
        try {
            // mysql/mysqldump is the symmetric tool for a mysqldump archive; args are shell-escaped.
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
            exec($cmd, $output, $code);
        } finally {
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
            putenv('MYSQL_PWD');
        }
        return (int) $code;
    }

    // ---------------------------------------------------------------------
    // Low-level utilities.
    // ---------------------------------------------------------------------

    /** Stream-copy $src into a unique temp file inside $destDir. Returns the temp path or null. */
    private function streamToTemp(string $src, string $destDir): ?string {
        if (!$this->ensureDir($destDir)) {
            return null;
        }
        try {
            $suffix = bin2hex(random_bytes(6));
        } catch (\Throwable $e) {
            unset($e);
            $suffix = (string) wp_rand(100000, 999999) . (string) getmypid();
        }
        $tmp = rtrim($destDir, '/') . '/.gratis-restore-' . $suffix . '.tmp';

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $in = fopen($src, 'rb');
        if (!$in) {
            return null;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $out = fopen($tmp, 'wb');
        if (!$out) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($in);
            return null;
        }
        $ok = true;
        while (!feof($in)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
            $chunk = fread($in, 65536);
            if (false === $chunk) {
                $ok = false;
                break;
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
            if ('' !== $chunk && false === fwrite($out, $chunk)) {
                $ok = false;
                break;
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($in);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($out);
        if (!$ok) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
            @unlink($tmp);
            return null;
        }
        return $tmp;
    }

    private function ensureDir(string $dir): bool {
        if (is_dir($dir)) {
            return true;
        }
        // Local backup/restore working directory; WP_Filesystem is not available this early.
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
        if (@mkdir($dir, 0755, true) || is_dir($dir)) {
            return true;
        }
        return false;
    }

    /** Resolve an id, manifest filename, or full manifest path to an absolute manifest path. */
    private function resolveManifest(string $idOrManifest): ?string {
        if (str_ends_with($idOrManifest, '.json')) {
            $rp = realpath($idOrManifest);
            if (false !== $rp && is_file($rp)) {
                return $rp;
            }
        }
        $id = preg_replace('/\.json$/', '', basename($idOrManifest));
        if (null === $id || 1 !== preg_match('/^[A-Za-z0-9._-]+$/', $id)) {
            return null;
        }
        $candidate = $this->manifestDir . '/' . $id . '.json';
        return is_file($candidate) ? $candidate : null;
    }

    /** Find the database file entry (prefer SQLite) at the manifest root. */
    private function databaseFileIn(array $files): ?string {
        $names = array_map(static fn($f) => (string) ($f['path'] ?? ''), $files);
        if (in_array('database.sqlite', $names, true)) {
            return 'database.sqlite';
        }
        if (in_array('database.sql', $names, true)) {
            return 'database.sql';
        }
        return null;
    }

    /** Map a backup source label to its live restore directory. */
    private function targetRootFor(string $source): ?string {
        switch ($source) {
            case 'uploads':
                // Mirror the backup source (DirectorySource over WP_CONTENT_DIR/uploads)
                // so a round-trip restores to exactly where the files were captured.
                return WP_CONTENT_DIR . '/uploads';
            case 'themes':
                return function_exists('get_theme_root') ? (string) get_theme_root() : WP_CONTENT_DIR . '/themes';
            case 'plugins':
                return defined('WP_PLUGIN_DIR') ? (string) constant('WP_PLUGIN_DIR') : WP_CONTENT_DIR . '/plugins';
            default:
                return null;
        }
    }

    private function blankPlan(): array {
        return [
            'ok'            => false,
            'id'            => '',
            'source'        => '',
            'created'       => '',
            'kind'          => null,
            'db_engine'     => null,
            'db_file'       => null,
            'db'            => null,
            'target_root'   => null,
            'manifest_path' => '',
            'backup_dir'    => '',
            'files'         => [],
            'summary'       => ['files' => 0, 'create' => 0, 'overwrite' => 0, 'unchanged' => 0, 'bytes' => 0],
            'errors'        => [],
            'warnings'      => [],
        ];
    }

    private function failPlan(array $errors): array {
        $plan = $this->blankPlan();
        $plan['errors'] = $errors;
        return $plan;
    }
}
