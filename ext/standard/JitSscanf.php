<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helper for sscanf() (issue #3190).
 */
final class JitSscanf
{
    public static function parse(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2) {
            throw new \LogicException('sscanf() requires at least two arguments');
        }
        $str = JitStringArg::lower($context, $args[0], 'sscanf() string');
        $fmt = JitStringArg::lower($context, $args[1], 'sscanf() format');
        $outCount = $argc - 2;
        $i64 = $context->getTypeFromString('int64');
        if (0 === $outCount) {
            $raw = $context->builder->call(
                $context->lookupFunction('__compiler_sscanf_array'),
                $str,
                $fmt
            );
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            $context->builder->call(
                $context->lookupFunction('__value__writeHashtable'),
                $ptr,
                $raw
            );

            return $ptr;
        }
        $ptrTy = $context->getTypeFromString('__value__*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $elemSize = $context->builder->ptrToInt(
            $context->builder->gep($ptrTy->pointerType(0)->constNull(), $i32->constInt(1, false)),
            $sizeT
        );
        $raw = $context->builder->call(
            $context->lookupFunction('__mm__malloc'),
            $context->builder->mul($elemSize, $context->builder->intCast($i64->constInt($outCount, false), $sizeT))
        );
        $outPtrs = $context->builder->pointerCast($raw, $context->getTypeFromString('__value__**'));
        for ($i = 0; $i < $outCount; ++$i) {
            $slot = $context->builder->inBoundsGEP(
                $outPtrs,
                $i64->constInt($i, false)
            );
            $valuePtr = JitValueBox::valuePtrFromVariable($context, $args[$i + 2]);
            $context->builder->store($valuePtr, $slot);
        }
        $count = $context->builder->call(
            $context->lookupFunction('__compiler_sscanf'),
            $str,
            $fmt,
            $i64->constInt($outCount, false),
            $outPtrs
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $raw);

        return $context->builder->intCast($count, $i64);
    }
}
