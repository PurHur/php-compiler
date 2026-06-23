<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * JIT/AOT runtime helper for htmlentities() UTF-8 entity translation (#10734).
 *
 * php-src: ext/standard/html.c — php_html_entities()
 */
final class HtmlEntitiesJitHelper
{
    public static function encode(string $string, int $flags): string
    {
        return VmString::htmlentities($string, $flags, 'UTF-8', true);
    }
}
