<?php

declare(strict_types=1);

namespace PHPCompiler\ext\phar;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\VM\Builtin\PharRunning;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;

/** Register ext/phar builtin classes (php-src ext/phar/phar.stub.php; #3436, #6490). */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        self::registerPhar($ctx);
        PharDataBuiltin::register($ctx);
    }

    public static function registerPhar(Context $ctx): void
    {
        if (isset($ctx->classes[VmPhar::CLASS_LC])) {
            return;
        }

        $pubStatic = CfgFunc::FLAG_PUBLIC | CfgFunc::FLAG_STATIC;
        $entry = new ClassEntry('Phar');
        $entry->methods['running'] = new PharRunning();
        $entry->methodVisibility['running'] = $pubStatic;
        $entry->isInternal = true;
        $ctx->classes[VmPhar::CLASS_LC] = $entry;
    }
}
