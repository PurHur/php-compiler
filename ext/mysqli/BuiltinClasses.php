<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\VM\Context;

/** Register mysqli + mysqli_result VM builtin classes (php-src ext/mysqli; #3435). */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        self::registerAuxiliaryClasses($ctx);
        VmMysqli::registerClass($ctx);
        VmMysqliResult::registerClass($ctx);
        VmMysqli::initStore($ctx);
    }

    private static function registerAuxiliaryClasses(Context $ctx): void
    {
        if (!isset($ctx->classes['mysqli_sql_exception'])) {
            $entry = new \PHPCompiler\VM\ClassEntry('mysqli_sql_exception');
            if (isset($ctx->classes['runtimeexception'])) {
                $entry->parentLc = 'runtimeexception';
            } elseif (isset($ctx->classes['exception'])) {
                $entry->parentLc = 'exception';
            }
            $entry->isInternal = true;
            $ctx->classes['mysqli_sql_exception'] = $entry;
        }

        if (!isset($ctx->classes['mysqli_warning'])) {
            $entry = new \PHPCompiler\VM\ClassEntry('mysqli_warning');
            if (isset($ctx->classes['exception'])) {
                $entry->parentLc = 'exception';
            }
            $entry->isInternal = true;
            $ctx->classes['mysqli_warning'] = $entry;
        }

        if (!isset($ctx->classes['mysqli_driver'])) {
            $entry = new \PHPCompiler\VM\ClassEntry('mysqli_driver');
            $entry->isInternal = true;
            $ctx->classes['mysqli_driver'] = $entry;
        }
    }
}
