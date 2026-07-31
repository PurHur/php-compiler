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
}
