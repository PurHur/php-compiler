<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

/**
 * Preg-free simple HTML element scan for user-script AOT loadHTML (#17954).
 *
 * Handles single-element documents like {@code <p id="target">hello</p>}
 * and unclosed variants {@code <div id="x">} (libxml EOF auto-close; #25988).
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
            return null;
        }
        $close = '</'.$tag.'>';
        $closePos = stripos($trimmed, $close, $gt + 1);
        if (false === $closePos) {
            // Unclosed non-optional tags: libxml auto-closes at EOF (#25988).
            $text = substr($trimmed, $gt + 1);
        } else {
            $text = substr($trimmed, $gt + 1, $closePos - $gt - 1);
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
