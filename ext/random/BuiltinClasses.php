<?php

declare(strict_types=1);

namespace PHPCompiler\ext\random;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;

/**
 * Register random builtin classes (php-src ext/random/random.c; issue #7102).
 *
 * OOP Randomizer API lands in #3722; v1 skeleton enables class_exists() and extension_loaded().
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        self::registerExceptions($ctx);
        RandomizerBuiltin::registerClasses($ctx);
        BuiltinEnums::register($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    private static function registerExceptions(Context $ctx): void
    {
        $randomException = new ClassEntry('Random\\RandomException');
        if (isset($ctx->classes['exception'])) {
            $randomException->parentLc = 'exception';
        }
        $ctx->classes['random\\randomexception'] = $randomException;

        $randomError = new ClassEntry('Random\\RandomError');
        if (isset($ctx->classes['error'])) {
            $randomError->parentLc = 'error';
        }
        $ctx->classes['random\\randomerror'] = $randomError;

        $brokenEngine = new ClassEntry('Random\\BrokenRandomEngineError');
        $brokenEngine->parentLc = 'random\\randomerror';
        $ctx->classes['random\\brokenrandomengineerror'] = $brokenEngine;
    }

}
