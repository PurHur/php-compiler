<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tokenizer;

use PHPCompiler\VM\Context;

/**
 * Register tokenizer builtin classes (php-src ext/tokenizer/tokenizer.c; issue #6940).
 *
 * PhpToken OOP API (#6077, #6794) — tokenize/is/getTokenName via VmPhpToken.
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        VmPhpToken::registerClass($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }
}
