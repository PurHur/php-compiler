<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

/**
 * Bundle manifest includes + entry into one compilation unit (issue #452 v1).
 */
final class SourceBundler
{
    private const BUNDLE_FILE_MARKER_PREFIX = 'PHPC_BUNDLE_FILE:';

    /** @var array<string, array<string, true>> */
    private static array $bundledUseImports = [];

    /** @var array<string, array<string, true>> */
    private static array $bundledDeclaredTypes = [];

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

        self::$bundledUseImports = [];
        self::$bundledDeclaredTypes = [];
        self::prescanBundledDeclaredTypes($includePaths, $projectRoot);
        self::prescanBundledDeclaredTypes([$entryPath], $projectRoot);
        $parts = ['declare(strict_types=1);'];
        foreach ($includePaths as $path) {
            $raw = file_get_contents($path);
            if (false === $raw) {
                throw new \RuntimeException('cannot read include: '.$path);
            }
            $parts[] = self::bundleFileMarker($path);
            $body = self::rewriteDeployPathIncludes(
                self::rewriteDirConstant(
                    self::stripStrictTypesDeclare(self::stripOpenTag($raw)),
                    $path,
                    $projectRoot
                )
            );
            if (self::isComposerAutoloadInclude($path)) {
                $body = self::composerAutoloadAotStub();
            }
            $base = basename($path);
            if (isset($requireTargets[$base])) {
                $body = self::rewriteReturnOnlyInclude($body, $requireTargets[$base]);
            }
            $body = self::stripResolvedRequires($body, $includePaths);
            $body = self::wrapInBracedNamespace($body);
            $parts[] = $body;
        }

        if ([] !== $includePaths) {
            $entryRaw = self::stripResolvedRequires($entryRaw, $includePaths);
        }
        $parts[] = self::bundleFileMarker($entryPath);
        $parts[] = self::wrapInBracedNamespace(
            self::rewriteDeployPathIncludes(
                self::stripStrictTypesDeclare(self::stripOpenTag($entryRaw))
            )
        );

        return ['<?php'."\n".implode("\n", $parts), $entryPath];
    }

    public static function isBundleFileMarker(string $line): bool
    {
        return str_contains($line, self::BUNDLE_FILE_MARKER_PREFIX);
    }

    /**
     * Reverse-map a 1-based line in a bundled compilation unit to original file + line (#13201).
     *
     * @return array{0: string, 1: int}|null
     */
    public static function mapBundledLine(string $bundledSource, int $bundleLine): ?array
    {
        if ($bundleLine <= 0) {
            return null;
        }
        $lines = preg_split('/\r\n|\n|\r/', $bundledSource) ?: [];
        $idx = $bundleLine - 1;
        if ($idx < 0 || $idx >= \count($lines)) {
            return null;
        }
        $currentFile = '';
        $markerLine1 = 0;
        for ($i = 0; $i <= $idx; ++$i) {
            $line = $lines[$i];
            if (!self::isBundleFileMarker($line)) {
                continue;
            }
            if (preg_match(
                '/'.preg_quote(self::BUNDLE_FILE_MARKER_PREFIX, '/').'\s+(.+?)\s*\*\//',
                $line,
                $m
            )) {
                $currentFile = trim($m[1]);
                $markerLine1 = $i + 1;
            }
        }
        if ('' === $currentFile || $markerLine1 <= 0) {
            return null;
        }

        return [$currentFile, max(1, $bundleLine - $markerLine1)];
    }

    private static function bundleFileMarker(string $absolutePath): string
    {
        // Keep this marker grep-friendly and stable: it is used to map parser errors back to the
        // concatenated file in AOT bundles (bootstrap vendor prelink triage, issue #1416).
        return sprintf("\n/* %s %s */\n", self::BUNDLE_FILE_MARKER_PREFIX, str_replace('*/', '* /', $absolutePath));
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
        // AOT bundles inline method-level includes at compile time (issue #54). Always emit a
        // compile-time literal directory: phpc_deploy_path() in bundled Router templates linked
        // but native execute printed empty stdout when dispatch called render* (#764, #784).
        // Explicit phpc_deploy_path() in project source is unchanged; only __DIR__ rewrite here.
        $replacement = var_export($dir, true);

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
        while ('' !== $code) {
            if (preg_match('/^<\?php/', $code)) {
                $code = substr($code, 5);
                break;
            }
            if (preg_match('/^<\?/', $code)) {
                $code = substr($code, 2);
                break;
            }
            if (preg_match('/^#.*(?:\R|\z)/', $code, $m)) {
                $code = substr($code, strlen($m[0]));

                continue;
            }
            break;
        }

        return ltrim($code, " \t\n\r\0\x0B");
    }

    /**
     * Concatenated AOT units keep one file-level strict_types (#764 MiniWebApp bundle).
     */
    private static function stripStrictTypesDeclare(string $code): string
    {
        $trimmed = ltrim($code);
        if (preg_match('/^declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/', $trimmed)) {
            $trimmed = preg_replace('/^declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;\s*/', '', $trimmed) ?? $trimmed;

            return ltrim($trimmed, " \t\n\r\0\x0B");
        }

        return $code;
    }

    /**
     * @param list<string> $paths
     */
    private static function prescanBundledDeclaredTypes(array $paths, ?string $projectRoot): void
    {
        foreach ($paths as $path) {
            $raw = file_get_contents($path);
            if (false === $raw) {
                continue;
            }
            $body = self::stripStrictTypesDeclare(self::stripOpenTag($raw));
            if (self::isComposerAutoloadInclude($path)) {
                continue;
            }
            $namespaceName = self::extractSemicolonNamespaceName($body) ?? '';
            self::registerBundledDeclaredTypes($namespaceName, $body);
        }
    }

    private static function registerBundledDeclaredTypes(string $namespaceName, string $body): void
    {
        if (!isset(self::$bundledDeclaredTypes[$namespaceName])) {
            self::$bundledDeclaredTypes[$namespaceName] = [];
        }
        if (!preg_match_all(
            '/\b(?:class|interface|trait|enum)\s+([A-Za-z_\x7f-\xff][A-Za-z0-9_\x7f-\xff]*)\b/',
            $body,
            $matches
        )) {
            return;
        }
        foreach ($matches[1] as $name) {
            self::$bundledDeclaredTypes[$namespaceName][self::normalizeUseImportLocalKey($name)] = true;
        }
    }

    /**
     * Isolate bundled compilation units so repeated `use` imports in the same namespace parse (#1416).
     */
    private static function wrapInBracedNamespace(string $code): string
    {
        $trimmed = ltrim($code);
        if ('' === $trimmed) {
            return $code;
        }
        if (preg_match('/^namespace\s+[^{;]+\s*\{/s', substr($trimmed, 0, 4096))) {
            return $code;
        }

        $namespaceName = self::extractSemicolonNamespaceName($trimmed);
        if (null === $namespaceName) {
            $body = self::dedupeBundledUseStatements('', $trimmed);

            return "namespace {\n".rtrim($body)."\n}\n";
        }

        $namespaceEnd = self::semicolonNamespaceBodyOffset($trimmed);
        if (null === $namespaceEnd) {
            $body = self::dedupeBundledUseStatements('', $trimmed);

            return "namespace {\n".rtrim($body)."\n}\n";
        }

        $namespaceStart = self::semicolonNamespaceKeywordOffset($trimmed);
        if (null === $namespaceStart) {
            $body = self::dedupeBundledUseStatements('', $trimmed);

            return "namespace {\n".rtrim($body)."\n}\n";
        }
        $prefix = substr($trimmed, 0, $namespaceStart);
        $body = substr($trimmed, $namespaceEnd);
        $body = self::dedupeBundledUseStatements($namespaceName, $body);

        return $prefix.'namespace '.$namespaceName." {\n".rtrim($body)."\n}\n";
    }

    private static function extractSemicolonNamespaceName(string $code): ?string
    {
        $tokens = token_get_all('<?php '.$code);
        $index = 0;
        if (isset($tokens[0]) && is_array($tokens[0]) && T_OPEN_TAG === $tokens[0][0]) {
            $index = 1;
        }

        for ($i = $index, $n = count($tokens); $i < $n; ++$i) {
            $token = $tokens[$i];
            if (!is_array($token) || T_NAMESPACE !== $token[0]) {
                continue;
            }
            $nameParts = [];
            for (++$i; $i < $n; ++$i) {
                $part = $tokens[$i];
                if (is_string($part)) {
                    if (';' === $part || '{' === $part) {
                        $name = implode('', $nameParts);

                        return '' === $name ? null : $name;
                    }

                    continue;
                }
                if (T_WHITESPACE === $part[0]) {
                    continue;
                }
                if (T_STRING === $part[0] || T_NAME_QUALIFIED === $part[0] || T_NAME_FULLY_QUALIFIED === $part[0]) {
                    $nameParts[] = $part[1];
                }
            }
            break;
        }

        return null;
    }

    private static function semicolonNamespaceBodyOffset(string $trimmed): ?int
    {
        $tokens = token_get_all('<?php '.$trimmed);
        $index = self::openTagTokenIndex($tokens);

        for ($i = $index, $n = count($tokens); $i < $n; ++$i) {
            $token = $tokens[$i];
            if (!is_array($token) || T_NAMESPACE !== $token[0]) {
                continue;
            }
            for (++$i; $i < $n; ++$i) {
                $part = $tokens[$i];
                if (is_string($part)) {
                    if (';' === $part) {
                        return self::tokenOffsetBefore($tokens, $index, $i) + 1;
                    }
                    if ('{' === $part) {
                        return null;
                    }
                }
            }
            break;
        }

        return null;
    }

    private static function semicolonNamespaceKeywordOffset(string $trimmed): ?int
    {
        $tokens = token_get_all('<?php '.$trimmed);
        $index = self::openTagTokenIndex($tokens);

        for ($i = $index, $n = count($tokens); $i < $n; ++$i) {
            $token = $tokens[$i];
            if (!is_array($token) || T_NAMESPACE !== $token[0]) {
                continue;
            }
            $namespaceIndex = $i;
            for (++$i; $i < $n; ++$i) {
                $part = $tokens[$i];
                if (is_string($part)) {
                    if (';' === $part || '{' === $part) {
                        return self::tokenOffsetBefore($tokens, $index, $namespaceIndex);
                    }
                }
            }
            break;
        }

        return null;
    }

    /**
     * @param list<mixed> $tokens
     */
    private static function openTagTokenIndex(array $tokens): int
    {
        if (isset($tokens[0]) && is_array($tokens[0]) && T_OPEN_TAG === $tokens[0][0]) {
            return 1;
        }

        return 0;
    }

    private static function dedupeBundledUseStatements(string $namespaceName, string $body): string
    {
        if (!isset(self::$bundledUseImports[$namespaceName])) {
            self::$bundledUseImports[$namespaceName] = ['stmt' => [], 'local' => []];
        }
        $seen =& self::$bundledUseImports[$namespaceName]['stmt'];
        $seenLocal =& self::$bundledUseImports[$namespaceName]['local'];
        $lines = preg_split('/\r\n|\n|\r/', $body) ?: [];
        $kept = [];
        /** @var list<array{local: string, alias: string}> */
        $rewrites = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*use\s+([^;]+);\s*$/', $line, $m)) {
                if (self::isBundledClassTraitUseLine($line, $m[1])) {
                    $kept[] = $line;
                    continue;
                }
                $stmt = trim($m[1]);
                $canonical = self::canonicalUseStmt($stmt);
                if (isset($seen[$canonical])) {
                    continue;
                }
                $local = self::useImportLocalName($stmt);
                $localKey = self::normalizeUseImportLocalKey($local);
                $declaredTypeConflict = isset(self::$bundledDeclaredTypes[$namespaceName][$localKey]);
                if (isset($seenLocal[$localKey])) {
                    if (self::canonicalUseStmt($seenLocal[$localKey]) === $canonical) {
                        continue;
                    }
                    $alias = self::uniqueBundledImportAlias($stmt, $namespaceName);
                    $aliasedStmt = self::appendUseAlias($stmt, $alias);
                    $aliasedCanonical = self::canonicalUseStmt($aliasedStmt);
                    if (isset($seen[$aliasedCanonical])) {
                        continue;
                    }
                    $seen[$aliasedCanonical] = true;
                    $seenLocal[self::normalizeUseImportLocalKey($alias)] = $aliasedStmt;
                    $rewrites[] = ['local' => $local, 'alias' => $alias];
                    $kept[] = 'use '.$aliasedStmt.';';

                    continue;
                }
                if ($declaredTypeConflict) {
                    $alias = self::uniqueBundledImportAlias($stmt, $namespaceName);
                    $aliasedStmt = self::appendUseAlias($stmt, $alias);
                    $aliasedCanonical = self::canonicalUseStmt($aliasedStmt);
                    if (isset($seen[$aliasedCanonical])) {
                        continue;
                    }
                    $seen[$aliasedCanonical] = true;
                    $seenLocal[self::normalizeUseImportLocalKey($alias)] = $aliasedStmt;
                    $rewrites[] = ['local' => $local, 'alias' => $alias];
                    $kept[] = 'use '.$aliasedStmt.';';

                    continue;
                }
                $seen[$canonical] = true;
                $seenLocal[$localKey] = $stmt;
            }
            $kept[] = $line;
        }

        $result = implode("\n", $kept);
        foreach ($rewrites as $rewrite) {
            $result = self::rewriteBundledLocalImportReferences(
                $result,
                $rewrite['local'],
                $rewrite['alias']
            );
        }

        return $result;
    }

    private static function normalizeUseImportLocalKey(string $local): string
    {
        return strtolower($local);
    }

    /** Indented unqualified `use Trait;` inside a class — not a top-level import. */
    private static function isBundledClassTraitUseLine(string $line, string $stmt): bool
    {
        if (!preg_match('/^\s+/', $line)) {
            return false;
        }
        if (str_contains($stmt, '\\')) {
            return false;
        }

        return true;
    }

    private static function canonicalUseStmt(string $stmt): string
    {
        if (preg_match('/^(.+)\s+as\s+(\w+)\s*$/', $stmt, $m)) {
            return trim($m[1]).' as '.strtolower($m[2]);
        }

        return $stmt;
    }

    private static function useImportLocalName(string $stmt): string
    {
        if (preg_match('/\bas\s+(\w+)\s*$/', $stmt, $m)) {
            return $m[1];
        }
        $parts = explode('\\', $stmt);

        return end($parts);
    }

    private static function bundledImportConflictAlias(string $stmt): string
    {
        if (preg_match('/\bas\s+(\w+)\s*$/', $stmt, $m)) {
            return $m[1].'Bundled';
        }
        $parts = explode('\\', $stmt);
        $name = array_pop($parts) ?: $stmt;
        if ([] !== $parts) {
            $parent = array_pop($parts) ?: $parts[0];

            return ucfirst(strtolower($parent)).$name;
        }

        return $name.'Bundled';
    }

    private static function uniqueBundledImportAlias(string $stmt, string $namespaceName): string
    {
        if (!isset(self::$bundledUseImports[$namespaceName])) {
            return self::bundledImportConflictAlias($stmt);
        }
        $seenLocal = self::$bundledUseImports[$namespaceName]['local'];
        $alias = self::bundledImportConflictAlias($stmt);
        $suffix = 2;
        while (isset($seenLocal[self::normalizeUseImportLocalKey($alias)])) {
            $alias = self::bundledImportConflictAlias($stmt).$suffix;
            ++$suffix;
        }

        return $alias;
    }

    private static function appendUseAlias(string $stmt, string $alias): string
    {
        if (preg_match('/\bas\s+\w+\s*$/', $stmt)) {
            return preg_replace('/\bas\s+\w+\s*$/', 'as '.$alias, $stmt) ?? $stmt;
        }

        return $stmt.' as '.$alias;
    }

    private static function rewriteBundledLocalImportReferences(
        string $code,
        string $localName,
        string $aliasName
    ): string {
        if ($localName === $aliasName) {
            return $code;
        }

        $tokens = token_get_all('<?php '.$code);
        $out = '';
        $skipOpenTag = true;
        $n = count($tokens);
        for ($i = 0; $i < $n; ++$i) {
            $token = $tokens[$i];
            if ($skipOpenTag) {
                if (is_array($token) && T_OPEN_TAG === $token[0]) {
                    continue;
                }
                $skipOpenTag = false;
            }
            if (is_array($token) && T_STRING === $token[0] && $token[1] === $localName) {
                $prev = self::previousMeaningfulToken($tokens, $i);
                if (null !== $prev && ('\\' === $prev || (is_array($prev) && T_NS_SEPARATOR === $prev[0]))) {
                    $out .= $token[1];
                    continue;
                }
                $out .= $aliasName;
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /** @return array{0: int, 1: string}|string|null */
    private static function previousMeaningfulToken(array $tokens, int $index): array|string|null
    {
        for ($i = $index - 1; $i >= 0; --$i) {
            $token = $tokens[$i];
            if (is_array($token) && T_WHITESPACE === $token[0]) {
                continue;
            }

            return $token;
        }

        return null;
    }

    private static function tokenOffsetBefore(array $tokens, int $startIndex, int $targetIndex): int
    {
        $offset = 0;
        for ($i = $startIndex; $i < $targetIndex; ++$i) {
            $token = $tokens[$i];
            $offset += is_array($token) ? strlen($token[1]) : strlen($token);
        }

        return $offset;
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
                // Method-body includes are JIT-inlined; never strip even when the path is bundled (#878).
                if (preg_match('/^\s+(include)(?:_once)?\s+/i', $line)) {
                    $kept[] = $line;
                    continue;
                }
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

    /** No-op: method-level includes use literal dirs from {@see rewriteDirConstant} (#764). */
    private static function rewriteDeployPathIncludes(string $code): string
    {
        return $code;
    }

    private static function isComposerAutoloadInclude(string $path): bool
    {
        return str_ends_with(str_replace('\\', '/', $path), '/vendor/autoload.php');
    }

    /** Composer autoload stub for AOT bundle (#1070). */
    private static function composerAutoloadAotStub(): string
    {
        return '// composer autoload stub for AOT bundle (issue #1070)';
    }
}
