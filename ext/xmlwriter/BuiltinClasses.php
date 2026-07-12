<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

use PHPCompiler\VM\Context;

/** Register XMLWriter builtin class (php-src ext/xmlwriter/php_xmlwriter.c; #6065). */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        VmXmlWriter::registerClass($ctx);
    }
}
