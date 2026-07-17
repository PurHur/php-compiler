<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * parse_str dispatch — embed + standalone AOT via ParseStrRuntime PHP (#9295, #13360, #13429, #20132).
 *
 * Embed / non-thin: {@see ParseStrRuntime::ensureLinked} → ParseStrJitHelper.
 * Thin standalone AOT (`isThinStandaloneAotMain`, #20028 shape): {@see ParseStrRuntime::ensureUserScriptLinked}
 * cstr delimited bridges (no NestedJIT defer gate).
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(parse_str)
 */
final class StringParseStr
{
    private const RUNTIME_FUNCTION = '__compiler_parse_str';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if ($context->isThinStandaloneAotMain()) {
            ParseStrRuntime::ensureUserScriptLinked($context);

            return;
        }

        $fn = $context->module->getNamedFunction(self::RUNTIME_FUNCTION);
        if (null !== $fn && $fn->countBasicBlocks() > 0) {
            $context->registerFunction(self::RUNTIME_FUNCTION, $fn);

            return;
        }

        ParseStrRuntime::ensureLinked($context);
    }
}
