<?php declare(strict_types=1);

namespace Gratis\Backup;

defined('ABSPATH') || exit;

/**
 * Path-traversal guard for restore operations.
 *
 * Backup manifests store file paths relative to a source root. Before joining
 * such a path onto a live target directory we must guarantee it cannot escape
 * that directory (no absolute paths, no "..", no drive letters). These helpers
 * are deliberately free of WordPress dependencies so the logic is unit-testable
 * in isolation.
 */
final class PathGuard {

    /**
     * Normalise a relative path and reject anything that could escape the root.
     *
     * @return string|null The cleaned, forward-slashed relative path, or null if unsafe/empty.
     */
    public static function safeRelative(string $rel): ?string {
        $rel = str_replace('\\', '/', trim($rel));
        if ('' === $rel) {
            return null;
        }
        if ('/' === $rel[0]) {
            return null; // Absolute path.
        }
        if (1 === preg_match('#^[A-Za-z]:#', $rel)) {
            return null; // Windows drive-letter path.
        }

        $parts = [];
        foreach (explode('/', $rel) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }
            if ('..' === $segment) {
                return null; // Directory traversal.
            }
            $parts[] = $segment;
        }

        return empty($parts) ? null : implode('/', $parts);
    }

    /**
     * Join a relative path onto a root directory, refusing to escape the root.
     *
     * @return string|null Absolute joined path, or null if the relative path is unsafe.
     */
    public static function join(string $root, string $rel): ?string {
        $safe = self::safeRelative($rel);
        if (null === $safe) {
            return null;
        }
        return rtrim($root, '/') . '/' . $safe;
    }

    /**
     * Confirm that an already-resolved real path stays within a root directory.
     * Use after realpath() to defend against symlinked components.
     */
    public static function isWithin(string $realPath, string $realRoot): bool {
        if ('' === $realPath || '' === $realRoot) {
            return false;
        }
        $root = rtrim($realRoot, '/') . '/';
        return $realPath === rtrim($realRoot, '/') || str_starts_with($realPath, $root);
    }
}
