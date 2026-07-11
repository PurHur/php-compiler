<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

/**
 * Preg-free simple HTML element scan for user-script AOT loadHTML (#17954).
 *
 * Handles single-element documents like {@code <p id="target">hello</p>}.
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
            return null;
        }
        $text = substr($trimmed, $gt + 1, $closePos - $gt - 1);

        return [
            'tag' => $tag,
            'id' => $id,
            'text' => $text,
        ];
    }

    private static function extractIdAttribute(string $openTag): ?string
    {
        $len = \strlen($openTag);
        $pos = 0;
        while ($pos < $len) {
            while ($pos < $len && ctype_space($openTag[$pos])) {
                ++$pos;
            }
            if ($pos >= $len) {
                break;
            }
            $nameEnd = $pos;
            while ($nameEnd < $len && !ctype_space($openTag[$nameEnd]) && '=' !== $openTag[$nameEnd]) {
                ++$nameEnd;
            }
            $name = strtolower(substr($openTag, $pos, $nameEnd - $pos));
            $cursor = $nameEnd;
            while ($cursor < $len && ctype_space($openTag[$cursor])) {
                ++$cursor;
            }
            if ($cursor >= $len || '=' !== $openTag[$cursor]) {
                $pos = $nameEnd > $pos ? $nameEnd : $pos + 1;

                continue;
            }
            ++$cursor;
            while ($cursor < $len && ctype_space($openTag[$cursor])) {
                ++$cursor;
            }
            if ($cursor >= $len) {
                break;
            }
            $quote = $openTag[$cursor];
            if ('"' !== $quote && "'" !== $quote) {
                $pos = $cursor;

                continue;
            }
            ++$cursor;
            $valueStart = $cursor;
            while ($cursor < $len && $openTag[$cursor] !== $quote) {
                ++$cursor;
            }
            if ($cursor >= $len) {
                break;
            }
            $value = substr($openTag, $valueStart, $cursor - $valueStart);
            if ('id' === $name) {
                return $value;
            }
            $pos = $cursor + 1;
        }

        return null;
    }
}
