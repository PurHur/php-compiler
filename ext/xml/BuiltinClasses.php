<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\VM\Context;

/**
 * Register xml extension placeholders (php-src ext/xml/xml.c; issue #7406).
 *
 * Parser resource lifecycle and SAX handlers land in #3494; v1 skeleton enables inventory.
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        XmlParserSupport::registerClass($ctx);
    }
}
