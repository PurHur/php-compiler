<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * JIT/AOT runtime helper for html_entity_decode() ENT_HTML5 paths (#4130).
 *
 * php-src: ext/standard/html.c — traverse_for_entities() with ent_ht_html5
 */
final class HtmlEntityDecodeJitHelper
{
    public static function decode(string $string, int $flags): string
    {
        return VmString::html_entity_decode($string, $flags);
    }

    public static function decodeWithEncoding(string $string, int $flags, string $encoding): string
    {
        return VmString::html_entity_decode($string, $flags, $encoding);
    }
}
