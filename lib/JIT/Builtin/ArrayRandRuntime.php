<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\ArrayRandLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\JitArrayElem;
use PHPLLVM\Value;

/**
 * JIT/AOT for array_rand() via {@see ArrayRandLlvm} (#16135, #27547).
 *
 * Emits pick LLVM into the caller (not NestedJIT of ArrayRandJitHelper returning
 * {@see \PHPCompiler\VM\Variable} — that path returned a dangling alloca under thin
 * standalone AOT → silent NULL; peer ArrayProduct #26968 / ArraySearch #27133).
 *
 * Host/VM SSOT remains {@see \PHPCompiler\ext\standard\VmArray::arrayRandPacked()}.
 * php-src: ext/standard/array.c — php_array_rand
 */
final class ArrayRandRuntime
{
    public static function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('array_rand() accepts one or two arguments');
        }
        JitArrayElem::requireArrayParam($context, $args[0], 'array_rand', 1, 'array');
        if (isset($args[1])) {
            JitInternalStrictArg::requireInt($context, $args[1], 'array_rand', 'num', 2);
            $num = JitLongArg::lower($context, $args[1], 'array_rand() num');
        } else {
            $num = $context->getTypeFromString('int64')->constInt(1, false);
        }

        $ht = ArrayBuiltinHelper::isNativeArray($args[0]->type)
            ? ArrayBuiltinHelper::nativeListToHashTable($context, $args[0])
            : ArrayBuiltinHelper::loadHashTable($context, $args[0]);

        return ArrayRandLlvm::pick($context, $ht, $num);
    }

    public static function ensureLinked(Context $context): void
    {
        // Inline emission in call() — nothing to pre-link (#27547).
        unset($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }
}
