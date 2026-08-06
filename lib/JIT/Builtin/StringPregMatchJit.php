<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * preg_* dispatch — embed + standalone AOT via PregMatchRuntime PHP (#5289, #9542, #12982).
 *
 * php-src: ext/pcre/php_pcre.c
 */
final class StringPregMatchJit
{
    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_preg_last_error',
        '__compiler_preg_last_error_msg',
        '__compiler_preg_match',
        '__compiler_preg_match_ex',
        '__compiler_preg_match_all',
        '__compiler_preg_match_all_ex',
        '__compiler_preg_replace',
        '__compiler_preg_replace_callback',
        '__compiler_preg_split',
    ];

    public static function implement(Context $context): void
    {
        // Always refresh — prelinked helper-runtime may ship a replaceCallbackArgv stub (#26820).
        PregMatchRuntime::ensureLinked($context);
        self::registerLinkedRuntime($context);
    }

    private static function resolveRuntimeFunction(Context $context, string $name): ?\PHPLLVM\Value\Function_
    {
        $fn = $context->module->getNamedFunction($name);
        if ((null === $fn || 0 === $fn->countBasicBlocks())
            && '__compiler_preg_replace_callback' === $name
        ) {
            $fn = $context->module->getNamedFunction('__compiler_preg_replace_callback_thin');
        }

        return $fn;
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = self::resolveRuntimeFunction($context, $name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after StringPregMatchJit dispatch (#9542)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
