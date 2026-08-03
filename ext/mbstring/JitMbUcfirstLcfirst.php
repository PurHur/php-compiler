<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helpers for mb_ucfirst() / mb_lcfirst() (php-src ext/mbstring/mbstring.c; #27330, #17609).
 *
 * Compile-time fold for string literals (same shape as mb_strtoupper / JitMbTrim). Runtime
 * non-literal args stay on the VM fallback for JIT; AOT requires a foldable call site.
 */
final class JitMbUcfirstLcfirst
{
    /**
     * @param JITVariable[] $args
     * @param callable(string, string): string $fold
     */
    public static function invoke(Context $context, string $function, callable $fold, array $args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException($function.'() requires one or two arguments');
        }

        // Soft-null — do not fold; JIT recovers via VM execute (#24176).
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            throw new \LogicException($function.'() is not lowered for JIT/AOT in this compiler build');
        }

        if (
            JITVariable::TYPE_STRING !== $args[0]->type
            || null === ($args[0]->compileTimeString ?? null)
        ) {
            throw new \LogicException($function.'() is not lowered for JIT/AOT in this compiler build');
        }

        $encoding = 'UTF-8';
        if ($argc >= 2) {
            if (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)) {
                throw new \LogicException($function.'() is not lowered for JIT/AOT in this compiler build');
            }
            if (
                JITVariable::TYPE_STRING !== $args[1]->type
                || null === ($args[1]->compileTimeString ?? null)
            ) {
                throw new \LogicException(
                    $function.'() JIT requires a compile-time encoding literal in this compiler build'
                );
            }
            $encoding = $args[1]->compileTimeString;
            // Unknown encoding → fall through (avoid ValueError during IR fold; #23883).
            if (!MbstringEncodingRegistry::isValid($encoding)) {
                throw new \LogicException($function.'() is not lowered for JIT/AOT in this compiler build');
            }
        }

        $result = $fold($args[0]->compileTimeString, $encoding);

        return $context->builder->load($context->constantStringFromString($result));
    }
}
