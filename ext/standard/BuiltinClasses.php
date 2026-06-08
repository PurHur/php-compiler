<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;

/**
 * Register ext/standard builtin classes (php-src ext/standard/streams.c; #7086, #7089).
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        self::registerStreamBucket($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    private static function registerStreamBucket(Context $ctx): void
    {
        $strProto = new Variable(Variable::TYPE_STRING);
        $entry = new ClassEntry('StreamBucket');
        $pub = CfgFunc::FLAG_PUBLIC;
        $entry->properties[] = new ClassProperty('bucket', null, $strProto);
        $entry->properties[] = new ClassProperty('data', null, $strProto);
        foreach ($entry->properties as $prop) {
            $prop->visibility = $pub;
        }
        $ctx->classes['streambucket'] = $entry;
    }
}
