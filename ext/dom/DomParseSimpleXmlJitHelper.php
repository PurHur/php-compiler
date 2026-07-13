<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

/**
 * Preg-free simple XML scan for user-script AOT loadXML (#18478).
 *
 * Handles documents like {@code <root><a/><b/></root>} for live-tag probes.
 */
final class DomParseSimpleXmlJitHelper
{
    public static function countTagArgv(string $xml, string $tag): int
    {
        $tag = strtolower($tag);
        if ('' === $tag) {
            return 0;
        }
        $needle = '<'.$tag;
        $count = 0;
        $offset = 0;
        while (false !== ($pos = stripos($xml, $needle, $offset))) {
            $after = $pos + \strlen($needle);
            if ($after >= \strlen($xml)) {
                break;
            }
            $next = $xml[$after];
            if ('>' === $next || '/' === $next || ' ' === $next) {
                ++$count;
            }
            $offset = $pos + 1;
        }

        return $count;
    }

    /**
     * @return null|array{0: int, 1: string} match count and first text content
     */
    public static function matchDescendantAttributeArgv(
        string $xml,
        string $tag,
        string $attr,
        string $value
    ): ?array {
        $tag = strtolower($tag);
        $attr = strtolower($attr);
        $needle = '<'.$tag;
        $count = 0;
        $firstText = null;
        $offset = 0;
        while (false !== ($pos = stripos($xml, $needle, $offset))) {
            $gt = strpos($xml, '>', $pos);
            if (false === $gt) {
                break;
            }
            $openTag = substr($xml, $pos, $gt - $pos + 1);
            $attrNeedle = $attr.'="'.str_replace('"', '', $value).'"';
            if (false !== stripos($openTag, $attrNeedle)) {
                $close = stripos($xml, '</'.$tag.'>', $gt + 1);
                if (false !== $close) {
                    $text = substr($xml, $gt + 1, $close - $gt - 1);
                    if (0 === $count) {
                        $firstText = $text;
                    }
                    ++$count;
                }
            }
            $offset = $pos + 1;
        }
        if (0 === $count) {
            return null;
        }

        return [$count, (string) $firstText];
    }

    public static function rootTagArgv(string $xml): string
    {
        if (preg_match('/<([a-zA-Z_][\w:.-]*)/', $xml, $matches)) {
            return strtolower($matches[1]);
        }

        return 'root';
    }
}
