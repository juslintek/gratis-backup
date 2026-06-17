<?php

/**
 * Standalone self-check for Gratis Backup restore logic.
 *
 * Exercises the WordPress-independent correctness paths — path-traversal guard,
 * manifest hash validation, overall-hash recompute (must match BackupEngine),
 * SQLite consistent copy + integrity check — without a running WordPress.
 *
 * Run:   php tests/restore-selfcheck.php
 * Exits: 0 when all checks pass, 1 otherwise.
 *
 * This is a CLI test harness, not plugin runtime code: it writes its own
 * throwaway fixtures with direct filesystem calls and prints to stdout, so the
 * web-output / WP_Filesystem / global-prefix / removed-extension sniffs do not
 * apply here.
 */

declare(strict_types=1);

namespace Gratis\Backup\Tests;

use Gratis\Backup\DatabaseBackend;
use Gratis\Backup\PathGuard;
use Gratis\Backup\RestoreEngine;

// phpcs:disable WordPress.Security.EscapeOutput
// phpcs:disable WordPress.WP.AlternativeFunctions
// phpcs:disable WordPress.PHP.NoSilencedErrors
// phpcs:disable WordPress.DB.RestrictedClasses
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
// phpcs:disable PHPCompatibility.Extensions.RemovedExtensions.sqliteRemoved

// The src classes guard on ABSPATH; define the minimum surface they need.
defined('ABSPATH') || define('ABSPATH', __DIR__ . '/');
defined('WP_CONTENT_DIR') || define('WP_CONTENT_DIR', sys_get_temp_dir() . '/gb-selfcheck-wpc');

require __DIR__ . '/../src/PathGuard.php';
require __DIR__ . '/../src/DatabaseBackend.php';
require __DIR__ . '/../src/RestoreEngine.php';

$failures = 0;
$check = static function (string $name, bool $cond) use (&$failures): void {
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $name . "\n";
    if (!$cond) {
        ++$failures;
    }
};

// --- PathGuard ----------------------------------------------------------
$check('PathGuard rejects ..', null === PathGuard::join('/root', '../etc/passwd'));
$check('PathGuard rejects nested ..', null === PathGuard::join('/root', 'a/../../b'));
$check('PathGuard rejects absolute', null === PathGuard::join('/root', '/etc/passwd'));
$check('PathGuard rejects empty', null === PathGuard::safeRelative(''));
$check('PathGuard normalizes', '/root/a/b/c' === PathGuard::join('/root/', 'a/./b//c'));
$check('PathGuard accepts simple', '2026/06/img.jpg' === PathGuard::safeRelative('2026/06/img.jpg'));

// --- Manifest hashing & validation -------------------------------------
$tmp = sys_get_temp_dir() . '/gb-selfcheck-' . uniqid('', true);
@mkdir($tmp, 0755, true);

$fixtures = ['database.sql' => "SELECT 1;\n", 'sub/a.txt' => str_repeat('x', 2048)];
$files = [];
foreach ($fixtures as $rel => $content) {
    $full = $tmp . '/' . $rel;
    @mkdir(\dirname($full), 0755, true);
    file_put_contents($full, $content);
    $files[] = ['path' => $rel, 'size' => \strlen($content), 'hash' => RestoreEngine::hashFile($full)];
}

$check('hashFile matches hash()', RestoreEngine::hashFile($tmp . '/database.sql') === hash('xxh3', "SELECT 1;\n"));
$check('validate clean archive', [] === RestoreEngine::validateBackupFiles($files, $tmp));
$check('overallHash matches engine algorithm', RestoreEngine::overallHash($files) === hash('xxh3', (string) json_encode($files)));

file_put_contents($tmp . '/sub/a.txt', 'tampered');
$check('validate detects corruption', [] !== RestoreEngine::validateBackupFiles($files, $tmp));

$withMissing = array_merge($files, [['path' => 'missing.bin', 'size' => 10, 'hash' => 'deadbeef']]);
$check('validate detects missing file', [] !== RestoreEngine::validateBackupFiles($withMissing, $tmp));

$traversal = RestoreEngine::validateBackupFiles([['path' => '../escape', 'size' => 1, 'hash' => 'x']], $tmp);
$check('validate rejects traversal path', [] !== $traversal);

// --- SQLite path resolution --------------------------------------------
$check(
    'sqliteFile default path',
    DatabaseBackend::sqliteFile() === rtrim(WP_CONTENT_DIR, '/') . '/database/.ht.sqlite'
);

// --- SQLite consistent copy + integrity (when a driver is present) ------
if (DatabaseBackend::hasPdoSqlite()) {
    $src = $tmp . '/src.sqlite';
    $pdo = new \PDO('sqlite:' . $src);
    $pdo->exec('CREATE TABLE t (a TEXT)');
    $pdo->exec("INSERT INTO t VALUES ('hi')");
    $pdo = null;

    $copy = $tmp . '/copy.sqlite';
    $copied = DatabaseBackend::copySqliteConsistently($src, $copy);
    $check('sqlite consistent copy created', $copied && is_file($copy));
    $check('sqlite copy integrity ok', DatabaseBackend::sqliteIntegrityOk($copy));

    $q = new \PDO('sqlite:' . $copy);
    $value = $q->query('SELECT a FROM t')->fetchColumn();
    $q = null;
    $check('sqlite copy preserves data', 'hi' === $value);

    $check('sqlite integrity rejects non-sqlite', !DatabaseBackend::sqliteIntegrityOk($tmp . '/database.sql'));
} else {
    echo "  SKIP  sqlite checks (no pdo_sqlite driver)\n";
}

// --- Cleanup ------------------------------------------------------------
if (is_dir($tmp)) {
    $rii = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($tmp, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($rii as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
    @rmdir($tmp);
}

echo "\n" . (0 === $failures ? "ALL CHECKS PASSED\n" : $failures . " CHECK(S) FAILED\n");
exit(0 === $failures ? 0 : 1);
