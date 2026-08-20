<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

/**
 * Preg-free simple HTML element scan for user-script AOT loadHTML (#17954).
 *
 * Handles single-element documents like {@code <p id="target">hello</p>}
 * and unclosed variants {@code <div id="x">} (libxml EOF auto-close; #25988).
 * Full {@code <html><body>…} documents: first nested id-bearing element (#32996).
 */
final class DomParseSimpleHtmlJitHelper
{
    /**
     * @return array{tag: string, id: string, text: string}|null
     */
    public static function parseArgv(string $html): ?array
    {
        $trimmed = trim($html);
        if ('' === $trimmed || '<' !== $trimmed[0]) {
            return null;
        }
        $gt = strpos($trimmed, '>');
        if (false === $gt || $gt < 2) {
            return null;
        }
        $openTag = substr($trimmed, 1, $gt - 1);
        $space = strpos($openTag, ' ');
        $tag = strtolower(false === $space ? $openTag : substr($openTag, 0, $space));
        if ('' === $tag) {
            return null;
        }
        $id = self::extractIdAttribute($openTag);
        if (null === $id) {
            // Root is html/body/wrapper without id — scan descendants (#32996).
            return self::parseFirstNestedIdElement($trimmed);
        }

        return self::elementRecordFromOpenTag($trimmed, $tag, $id, $gt);
    }

    /**
     * Locate a specific id= element inside a compile-time HTML literal (#32996).
     *
     * @return array{tag: string, id: string, text: string}|null
     */
    public static function parseIdElementArgv(string $html, string $wantId): ?array
    {
        if ('' === $wantId) {
            return null;
        }
        $trimmed = trim($html);
        if ('' === $trimmed || '<' !== $trimmed[0]) {
            return null;
        }

        return self::scanIdElements($trimmed, $wantId);
    }

    /**
     * @return array{tag: string, id: string, text: string}|null
     */
    private static function parseFirstNestedIdElement(string $html): ?array
    {
        return self::scanIdElements($html, null);
    }

    /**
     * Preg-free scan for an opening tag with id=. When {@see $wantId} is null, return the first hit.
     *
     * @return array{tag: string, id: string, text: string}|null
     */
    private static function scanIdElements(string $html, ?string $wantId): ?array
    {
        $len = \strlen($html);
        $pos = 0;
        while ($pos < $len) {
            $lt = strpos($html, '<', $pos);
            if (false === $lt || $lt + 1 >= $len) {
                break;
            }
            $next = $html[$lt + 1];
            // Skip closers, comments/doctype (!), and processing instructions (?).
            if ('/' === $next || '!' === $next || '?' === $next) {
                $pos = $lt + 2;
                continue;
            }
            $gt = strpos($html, '>', $lt + 1);
            if (false === $gt || $gt <= $lt + 1) {
                break;
            }
            $openTag = substr($html, $lt + 1, $gt - $lt - 1);
            // Strip trailing '/' from empty-element open tags.
            if ($openTag !== '' && '/' === $openTag[\strlen($openTag) - 1]) {
                $openTag = rtrim(substr($openTag, 0, -1));
            }
            $space = strpos($openTag, ' ');
            $tag = strtolower(false === $space ? $openTag : substr($openTag, 0, $space));
            if ('' === $tag) {
                $pos = $gt + 1;
                continue;
            }
            $id = self::extractIdAttribute($openTag);
            if (null === $id) {
                $pos = $gt + 1;
                continue;
            }
            if (null !== $wantId) {
                $decodedId = VmDom::decodeHtmlCharacterReferences($id);
                $decodedWant = VmDom::decodeHtmlCharacterReferences($wantId);
                if ($decodedId !== $decodedWant) {
                    $pos = $gt + 1;
                    continue;
                }
            }
            $relative = substr($html, $lt);
            $relGt = strpos($relative, '>');
            if (false === $relGt) {
                break;
            }

            return self::elementRecordFromOpenTag($relative, $tag, $id, $relGt);
        }

        return null;
    }

    /**
     * @return array{tag: string, id: string, text: string}
     */
    private static function elementRecordFromOpenTag(
        string $fromOpen,
        string $tag,
        string $id,
        int $gt
    ): array {
        $close = '</'.$tag.'>';
        $closePos = stripos($fromOpen, $close, $gt + 1);
        if (false === $closePos) {
            // Unclosed non-optional tags: libxml auto-closes at EOF (#25988).
            $text = substr($fromOpen, $gt + 1);
        } else {
            $text = substr($fromOpen, $gt + 1, $closePos - $gt - 1);
        }
        // Match VmDom::loadHTML / libxml htmlReadMemory entity expansion (#20260).
        $text = VmDom::decodeHtmlCharacterReferences($text);
        $id = VmDom::decodeHtmlCharacterReferences($id);

        return [
            'tag' => $tag,
            'id' => $id,
            'text' => $text,
        ];
    }

    private static function extractIdAttribute(string $openTag): ?string
    {
        $space = strpos($openTag, ' ');
        $attrPart = false === $space ? '' : substr($openTag, $space);
        $attrs = VmDom::parseMarkupAttributes($attrPart);

        return $attrs['id'] ?? null;
    }

    /**
     * Explicit HTML doctype name for Dom\HTMLDocument::createFromString AOT (#28940).
     *
     * Returns null when the source has no {@code <!DOCTYPE …>} (php-src fragment
     * parse → {@code $doc->doctype === null}).
     */
    public static function doctypeNameArgv(string $html): ?string
    {
        $trimmed = trim($html);
        if ('' === $trimmed) {
            return null;
        }
        if (1 !== preg_match('/^<!DOCTYPE\s+([a-zA-Z_][\w:.-]*)/i', $trimmed, $m)) {
            return null;
        }

        return strtolower($m[1]);
    }

    /**
     * Body element textContent for Dom\HTMLDocument::createFromString AOT (#27300).
     *
     * Mirrors loadHTML wrapping: explicit {@code <body>} wins; otherwise the
     * fragment (minus doctype / outer html) becomes the implied body content.
     */
    public static function bodyTextContentArgv(string $html): ?string
    {
        $trimmed = trim($html);
        if ('' === $trimmed || '<' !== $trimmed[0]) {
            return null;
        }
        if (1 === preg_match('/<body\b[^>]*>(.*?)<\/body>/is', $trimmed, $m)) {
            return VmDom::decodeHtmlCharacterReferences(self::stripTagsToText($m[1]));
        }
        $withoutDoctype = preg_replace('/^<!DOCTYPE[^>]*>\s*/i', '', $trimmed) ?? $trimmed;
        $withoutDoctype = trim($withoutDoctype);
        if ('' === $withoutDoctype) {
            return null;
        }
        if (1 === preg_match('/<html\b[^>]*>(.*?)<\/html>/is', $withoutDoctype, $m)) {
            $inner = $m[1];
            if (1 === preg_match('/<body\b[^>]*>(.*?)<\/body>/is', $inner, $bm)) {
                return VmDom::decodeHtmlCharacterReferences(self::stripTagsToText($bm[1]));
            }
            // Strip head so title/script do not pollute implied body textContent.
            $inner = preg_replace('/<head\b[^>]*>.*?<\/head>/is', '', $inner) ?? $inner;

            return VmDom::decodeHtmlCharacterReferences(self::stripTagsToText($inner));
        }

        return VmDom::decodeHtmlCharacterReferences(self::stripTagsToText($withoutDoctype));
    }

    private static function stripTagsToText(string $markup): string
    {
        // php-src textContent concatenates descendant text nodes (no inter-element spaces).
        return strip_tags($markup);
    }
}
