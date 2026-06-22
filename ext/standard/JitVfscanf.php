<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\Sscanf;
use PHPCompiler\JIT\Builtin\StreamRead;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helper for vfscanf() (issue #6174).
 */
final class JitVfscanf
{
    public static function parse(Context $context, JITVariable ...$args): Value
    {
        Sscanf::ensureLinked($context);
        StreamRead::ensureLinked($context);

        $argc = \count($args);
        if ($argc < 2) {
            throw new \LogicException('vfscanf() expects at least two arguments');
        }

        $handleLit = $args[0]->compileTimeLong ?? null;
        $fmtLit = $args[1]->compileTimeString ?? null;
        if (null !== $handleLit && null !== $fmtLit && self::canFoldCompileTime($fmtLit, $argc - 2)) {
            return self::parseCompileTime($context, (int) $handleLit, $fmtLit, \array_slice($args, 2));
        }

        $i64 = $context->getTypeFromString('int64');
        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], 'vfscanf() stream'),
            $i64
        );
        $fmt = JitStringBuiltinArg::lower($context, $args[1], 'vfscanf', 1, 'format');
        $outCount = $argc - 2;
        if (0 === $outCount) {
            throw new \LogicException('vfscanf() without by-ref targets requires compile-time stream/format in this compiler build');
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
            $context->lookupFunction('__compiler_vfscanf'),
            $handle,
            $fmt,
            $i64->constInt($outCount, false),
            $outPtrs
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $raw);

        return $context->builder->intCast($count, $i64);
    }

    /** Compile-time fold only when arity is valid — mismatches use runtime LLVM (#4064). */
    private static function canFoldCompileTime(string $format, int $outCount): bool
    {
        if (0 === $outCount) {
            return true;
        }
        try {
            VmSscanf::validateOutVarArity($format, $outCount);

            return true;
        } catch (\ValueError) {
            return false;
        }
    }

    /**
     * @param list<JITVariable> $outArgs
     */
    private static function parseCompileTime(Context $context, int $handle, string $format, array $outArgs): Value
    {
        $i64 = $context->getTypeFromString('int64');
        if ([] === $outArgs) {
            $ht = VmVfscanf::parseToArray($handle, $format);
            $htVar = JitSscanf::materializeVmHashTable($context, $ht);
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            $context->builder->call(
                $context->lookupFunction('__value__writeHashtable'),
                $ptr,
                HashTableHelper::loadHashtablePointer($context, $htVar)
            );

            return $ptr;
        }

        $temps = [];
        foreach ($outArgs as $_) {
            $temps[] = new VMVariable();
        }
        $assigned = VmVfscanf::parse($handle, $format, $temps);
        for ($i = 0; $i < $assigned; ++$i) {
            JitSscanf::writeVmVarToOut($context, $outArgs[$i], $temps[$i]);
        }

        return $i64->constInt($assigned, false);
    }
}
