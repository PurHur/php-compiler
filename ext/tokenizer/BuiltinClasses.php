<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tokenizer;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;

/**
 * Register tokenizer builtin classes (php-src ext/tokenizer/tokenizer.c; issue #6940).
 *
 * PhpToken OOP API lands in #6077; v1 skeleton enables class_exists().
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        self::registerPhpToken($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    private static function registerPhpToken(Context $ctx): void
    {
        $ctx->classes['phptoken'] = new ClassEntry('PhpToken');
    }
}
