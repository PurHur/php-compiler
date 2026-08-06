<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * URL-Rewriter flush entry (ext/standard/url_scanner_ex.re, #24370).
 *
 * Kept separate from {@see VmUrlRewriterOb} so RewriteVarsRuntime NestedJIT only lowers
 * registration helpers.
 */
final class VmUrlRewriterFlush
{
    public static function applyHandler(string $content): string
    {
        $pairs = VmOutputRewriteVars::listPairs();
        if ([] === $pairs) {
            return $content;
        }

        return UrlScannerEx::adapt(
            $content,
            $pairs,
            self::parseTags(OutputRewriteVarsJitHelper::getTags()),
            self::parseHosts(OutputRewriteVarsJitHelper::getHosts()),
            '&'
        );
    }

    /**
     * @return array<string, string>
     */
    private static function parseTags(string $tagsIni): array
    {
        $out = [];
        foreach (\explode(',', $tagsIni) as $piece) {
            $piece = \trim($piece);
            if ('' === $piece) {
                continue;
            }
            $eq = \strpos($piece, '=');
            if (false === $eq) {
                continue;
            }
            $tag = \strtolower(\substr($piece, 0, $eq));
            $attr = \strtolower(\substr($piece, $eq + 1));
            if ('' === $tag) {
                continue;
            }
            $out[$tag] = $attr;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function parseHosts(string $hostsIni): array
    {
        $out = [];
        foreach (\explode(',', $hostsIni) as $piece) {
            $host = \strtolower(\trim($piece));
            if ('' !== $host) {
                $out[] = $host;
            }
        }

        return $out;
    }
}
