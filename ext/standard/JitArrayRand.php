<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM JIT helpers for array_rand() (issue #2321). */
final class JitArrayRand
{
    public static function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('array_rand() accepts one or two arguments');
        }
        if (ArrayBuiltinHelper::isNativeArray($args[0]->type)) {
            throw new \LogicException(
                'array_rand() cannot compile fixed-size literal arrays in JIT/AOT yet; use bin/vm.php or bin/serve.php, or build the list with [] append'
            );
        }
        $sizeT = $context->getTypeFromString('size_t');
        $num = isset($args[1])
            ? JitLongArg::lower($context, $args[1], 'array_rand() num')
            : $sizeT->constInt(1, false);

        $resultSlot = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $resultSlot);
        $ht = ArrayBuiltinHelper::loadHashTable($context, $args[0]);
        $context->builder->call(
            $context->lookupFunction('__hashtable__arrayRandPacked'),
            $ht,
            $num,
            $resultPtr
        );

        return $resultPtr;
    }
}
