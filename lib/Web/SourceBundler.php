<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

/**
 * Bundle manifest includes + entry into one compilation unit (issue #452 v1).
 */
final class SourceBundler
{
    /**
     * @param list<string> $includePaths absolute paths, compiled before entry
     *
     * @return array{0: string, 1: string} bundled source and logical filename (entry)
     */
    public static function bundleForAot(string $entryPath, array $includePaths): array
    {
        $entryRaw = file_get_contents($entryPath);
        if (false === $entryRaw) {
            throw new \RuntimeException('cannot read entry: '.$entryPath);
        }

        $requireTargets = self::requireAssignmentTargets($entryRaw, $includePaths);

        $parts = [];
        foreach ($includePaths as $path) {
            $raw = file_get_contents($path);
            if (false === $raw) {
                throw new \RuntimeException('cannot read include: '.$path);
            }
            $body = self::stripOpenTag($raw);
            $base = basename($path);
            if (isset($requireTargets[$base])) {
                $body = self::rewriteReturnOnlyInclude($body, $requireTargets[$base]);
            }
            $parts[] = $body;
        }

        if ([] !== $includePaths) {
            $entryRaw = self::stripResolvedRequires($entryRaw, $includePaths);
        }
        $parts[] = self::stripOpenTag($entryRaw);

        return ['<?php'."\n".implode("\n", $parts), $entryPath];
    }

    /**
     * Map bundled basename -> LHS variable from `$var = require 'file.php'` in the entry.
     *
     * @param list<string> $includePaths
     *
     * @return array<string, string>
     */
    private static function requireAssignmentTargets(string $entryCode, array $includePaths): array
    {
        $targets = [];
        $lines = preg_split('/\r\n|\n|\r/', $entryCode) ?: [];
        foreach ($includePaths as $path) {
            $base = basename($path);
            foreach ($lines as $line) {
                if (!str_contains($line, $base)) {
                    continue;
                }
                if (preg_match(
                    '/^\s*\$(\w+)\s*=\s*(?:require|include)(?:_once)?\b/i',
                    $line,
                    $m
                )) {
                    $targets[$base] = '$'.$m[1];
                    break;
                }
            }
        }

        return $targets;
    }

    /**
     * Replace top-level `return <expr>;` in an included file with `$var = <expr>;` for AOT bundle.
     */
    private static function rewriteReturnOnlyInclude(string $body, string $lhs): string
    {
        $trimmed = trim($body);
        $prefix = '';
        if (preg_match('/^(declare\s*\([^)]+\)\s*;\s*)/s', $trimmed, $decl)) {
            $prefix = $decl[1];
            $trimmed = trim(substr($trimmed, strlen($decl[1])));
        }
        if (preg_match('/^return\s+(.+);\s*$/s', $trimmed, $m)) {
            return $prefix.$lhs.' = '.$m[1].';';
        }

        return $body;
    }

    private static function stripOpenTag(string $code): string
    {
        $code = ltrim($code);
        if (str_starts_with($code, '<?php')) {
            $code = substr($code, 5);
        } elseif (str_starts_with($code, '<?')) {
            $code = substr($code, 2);
        }

        return ltrim($code, " \t\n\r\0\x0B");
    }

    /**
     * Remove literal include/require lines satisfied by bundled paths (issue #54).
     *
     * @param list<string> $bundledAbsolute
     */
    private static function stripResolvedRequires(string $code, array $bundledAbsolute): string
    {
        $lines = preg_split('/\r\n|\n|\r/', $code) ?: [];
        $drop = [];
        foreach ($bundledAbsolute as $abs) {
            $drop[basename($abs)] = true;
        }
        $kept = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*(require|include)(?:_once)?\s+/i', $line)) {
                $remove = false;
                foreach ($drop as $base => $_) {
                    if (str_contains($line, $base)) {
                        $remove = true;
                        break;
                    }
                }
                if ($remove) {
                    continue;
                }
            }
            $kept[] = $line;
        }

        return implode("\n", $kept);
    }
}
