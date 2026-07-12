<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\VM\Context;

/** Register XMLReader builtin class (php-src ext/xmlreader/php_xmlreader.c; #6135). */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        VmXmlReader::registerClass($ctx);
    }
}
