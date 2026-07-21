<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\VM\Context;

/** Register mysqli + mysqli_result VM builtin classes (php-src ext/mysqli; #3435). */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        VmMysqli::registerClass($ctx);
        VmMysqliResult::registerClass($ctx);
        VmMysqli::initStore($ctx);
    }
}
