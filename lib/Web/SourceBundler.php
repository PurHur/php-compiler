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
        $parts = [];
        foreach ($includePaths as $path) {
            $raw = file_get_contents($path);
            if (false === $raw) {
                throw new \RuntimeException('cannot read include: '.$path);
            }
            $parts[] = self::stripOpenTag($raw);
        }

        $entryRaw = file_get_contents($entryPath);
        if (false === $entryRaw) {
            throw new \RuntimeException('cannot read entry: '.$entryPath);
        }
        if ([] !== $includePaths) {
            $entryRaw = self::stripResolvedRequires($entryRaw, $includePaths);
        }
        $parts[] = self::stripOpenTag($entryRaw);

        return ['<?php'."\n".implode("\n", $parts), $entryPath];
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
