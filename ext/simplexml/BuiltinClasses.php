<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\VM\Context;

/** Register SimpleXMLElement builtin class (php-src ext/simplexml/php_simplexml.c; #3338). */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        VmSimpleXml::registerClass($ctx);
        VmSimpleXmlIterator::registerClass($ctx);
    }
}
