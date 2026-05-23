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
    public static function bundleForAot(string $entryPath, array $includePaths, ?string $projectRoot = null): array
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
            $body = self::rewriteDeployPathIncludes(
                self::rewriteDirConstant(self::stripOpenTag($raw), $path, $projectRoot)
            );
            $base = basename($path);
            if (isset($requireTargets[$base])) {
                $body = self::rewriteReturnOnlyInclude($body, $requireTargets[$base]);
            }
            $parts[] = $body;
        }

        if ([] !== $includePaths) {
            $entryRaw = self::stripResolvedRequires($entryRaw, $includePaths);
        }
        $parts[] = self::rewriteDeployPathIncludes(self::stripOpenTag($entryRaw));

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

    /**
     * Preserve per-file directory semantics when sources are concatenated (issue #485).
     */
    private static function rewriteDirConstant(string $code, string $sourceFile, ?string $projectRoot = null): string
    {
        $dir = dirname(realpath($sourceFile) ?: $sourceFile);
        if (null === $projectRoot) {
            $replacement = var_export($dir, true);
        } else {
            $rel = DeployRoot::relativeDirFromProject($dir, $projectRoot);
            $replacement = 'phpc_deploy_path('.var_export($rel, true).', '.var_export($dir, true).')';
        }

        $tokens = token_get_all('<?php '.$code);
        $out = '';
        $skipOpenTag = true;
        foreach ($tokens as $token) {
            if ($skipOpenTag) {
                if (is_array($token) && T_OPEN_TAG === $token[0]) {
                    continue;
                }
                $skipOpenTag = false;
            }
            if (is_array($token)) {
                if (T_DIR === $token[0]) {
                    $out .= $replacement;
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }

        return $out;
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
            if (preg_match('/\b(require|include)(?:_once)?\s+/i', $line)) {
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

    /**
     * Keep phpc_deploy_path() . '/suffix' includes for runtime PHPC_DEPLOY_ROOT (#623).
     */
    private static function rewriteDeployPathIncludes(string $code): string
    {
        return $code;
    }
}
