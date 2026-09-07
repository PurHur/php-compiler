<?php

declare(strict_types=1);

/**
 * JIT/AOT helper for str_replace() — routes through StrReplaceJitHelper PHP (#14779, #23912).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringStrReplace;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

final class JitStrReplace
{
    public static function replace(
        Context $context,
        Value $search,
        Value $replace,
        Value $subject,
        bool $caseInsensitive = false,
        ?Value $countSlot = null
    ): Value {
        return StringStrReplace::invoke(
            $context,
            $search,
            $replace,
            $subject,
            $caseInsensitive,
            $countSlot
        );
    }

    /**
     * Heap-owning compile-time fold for scalar literal str_replace (#36388).
     * php-src: ext/standard/string.c php_str_replace / php_str_to_str_ex.
     */
    public static function tryFoldLiteralReplace(
        Context $context,
        JITVariable $search,
        JITVariable $replace,
        JITVariable $subject,
        bool $caseInsensitive = false,
        ?Value $countSlot = null
    ): ?Value {
        $searchLit = $search->compileTimeString ?? null;
        $replaceLit = $replace->compileTimeString ?? null;
        $subjectLit = $subject->compileTimeString ?? null;
        if (null === $searchLit || null === $replaceLit || null === $subjectLit) {
            return null;
        }
        $count = 0;
        $folded = $caseInsensitive
            ? \str_ireplace($searchLit, $replaceLit, $subjectLit, $count)
            : \str_replace($searchLit, $replaceLit, $subjectLit, $count);
        if (null !== $countSlot) {
            $context->builder->store(
                $context->getTypeFromString('int64')->constInt((int) $count, false),
                $countSlot
            );
        }
        $lit = $context->builder->load($context->constantStringFromString($folded));

        return $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $lit
        );
    }
}
