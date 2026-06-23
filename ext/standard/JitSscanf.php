<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\Sscanf;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helper for sscanf() (issue #3190).
 */
final class JitSscanf
{
    public static function parse(Context $context, JITVariable ...$args): Value
    {
        Sscanf::ensureLinked($context);

        $argc = \count($args);
        if ($argc < 2) {
            throw new \LogicException('sscanf() requires at least two arguments');
        }

        $strLit = $args[0]->compileTimeString ?? null;
        $fmtLit = $args[1]->compileTimeString ?? null;
        if (null !== $strLit && null !== $fmtLit && self::canFoldCompileTime($fmtLit, $argc - 2)) {
            return self::parseCompileTime($context, $strLit, $fmtLit, \array_slice($args, 2));
        }

        $str = JitStringBuiltinArg::lowerRequiredString($context, $args[0], 'sscanf', 0, 'string');
        $fmt = JitStringBuiltinArg::lower($context, $args[1], 'sscanf', 1, 'format');
        $outCount = $argc - 2;
        $i64 = $context->getTypeFromString('int64');
        if (0 === $outCount) {
            return $context->builder->call(
                $context->lookupFunction('__compiler_sscanf_array'),
                $str,
                $fmt
            );
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
    private static function parseCompileTime(Context $context, string $input, string $format, array $outArgs): Value
    {
        $i64 = $context->getTypeFromString('int64');
        if ([] === $outArgs) {
            $ht = VmSscanf::parseToArray($input, $format);
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            if (null === $ht) {
                $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);
            } else {
                $htVar = self::materializeVmHashTable($context, $ht);
                $context->builder->call(
                    $context->lookupFunction('__value__writeHashtable'),
                    $ptr,
                    HashTableHelper::loadHashtablePointer($context, $htVar)
                );
            }

            return $ptr;
        }

        $temps = [];
        foreach ($outArgs as $_) {
            $temps[] = new VMVariable();
        }
        $assigned = VmSscanf::parse($input, $format, $temps);
        for ($i = 0; $i < $assigned; ++$i) {
            self::writeVmVarToOut($context, $outArgs[$i], $temps[$i]);
        }

        return $i64->constInt($assigned, false);
    }

    public static function writeVmVarToOut(Context $context, JITVariable $dest, VMVariable $src): void
    {
        $destPtr = JitValueBox::valuePtrFromVariable($context, $dest);
        $i64 = $context->getTypeFromString('int64');
        switch ($src->type) {
            case VMVariable::TYPE_INTEGER:
                $context->builder->call(
                    $context->lookupFunction('__value__writeLong'),
                    $destPtr,
                    $i64->constInt($src->toInt(), false)
                );
                break;
            case VMVariable::TYPE_STRING:
                $context->builder->call(
                    $context->lookupFunction('__value__writeString'),
                    $destPtr,
                    $context->builder->load($context->constantStringFromString($src->toString()))
                );
                break;
            case VMVariable::TYPE_FLOAT:
                $context->builder->call(
                    $context->lookupFunction('__value__writeDouble'),
                    $destPtr,
                    $context->constantFromFloat($src->toFloat())
                );
                break;
            default:
                throw new \LogicException('Unsupported sscanf() compile-time result type');
        }
    }

    public static function materializeVmHashTable(Context $context, \PHPCompiler\VM\HashTable $table): JITVariable
    {
        $ht = HashTableHelper::alloc($context);
        $setLong = $context->lookupFunction('__hashtable__setLongAt');
        $setStringAt = $context->lookupFunction('__hashtable__setStringAt');
        $setDouble = $context->lookupFunction('__hashtable__setDoubleAt');
        $i64 = $context->getTypeFromString('int64');
        foreach ($table->iterateKeyed(true) as [$keyVar, $valueVar]) {
            $resolved = $valueVar->resolveIndirect();
            if (VMVariable::TYPE_INTEGER !== $keyVar->type) {
                return HashTableHelper::variableFromVmHashTable($context, $table);
            }
            $idx = $context->constantFromInteger($keyVar->toInt(), 'size_t');
            if (VMVariable::TYPE_INTEGER === $resolved->type) {
                $context->builder->call(
                    $setLong,
                    $ht,
                    $idx,
                    $i64->constInt($resolved->toInt(), false)
                );
            } elseif (VMVariable::TYPE_STRING === $resolved->type) {
                $str = $context->builder->load(
                    $context->constantStringFromString($resolved->toString())
                );
                $context->builder->call($setStringAt, $ht, $idx, $str);
            } elseif (VMVariable::TYPE_FLOAT === $resolved->type) {
                $context->builder->call(
                    $setDouble,
                    $ht,
                    $idx,
                    $context->constantFromFloat($resolved->toFloat())
                );
            } else {
                return HashTableHelper::variableFromVmHashTable($context, $table);
            }
        }

        return new JITVariable(
            $context,
            JITVariable::TYPE_HASHTABLE,
            JITVariable::KIND_VALUE,
            $ht
        );
    }
}
