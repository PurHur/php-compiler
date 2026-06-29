<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for vfprintf()/vprintf() via __compiler_sprintf + __compiler_fwrite (#3752). */
final class JitVfprintf
{
    /** @return Value */
    public static function invoke(Context $context, Value $handleLong, Value $fmt, JITVariable $args): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $fmtVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $fmt);

        if ($args->type & JITVariable::IS_NATIVE_ARRAY) {
            $formatted = JitSprintf::format($context, $fmtVar, ...self::nativeArrayElements($context, $args));

            return JitFwrite::invoke($context, $handleLong, $formatted, JitFwrite::lengthWriteAll($context, $formatted));
        }

        $htVar = HashTableHelper::coerceToPackedHashtable($context, $args);
        $ht = $context->helper->loadValue($htVar);
        $formatted = self::formatFromHashtable($context, $fmt, $ht);

        return JitFwrite::invoke($context, $handleLong, $formatted, JitFwrite::lengthWriteAll($context, $formatted));
    }

    /** @return list<JITVariable> */
    private static function nativeArrayElements(Context $context, JITVariable $array): array
    {
        if (0 === ($array->type & JITVariable::IS_NATIVE_ARRAY)) {
            throw new \LogicException('nativeArrayElements requires a native array');
        }
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $elemType = $array->type & ~JITVariable::IS_NATIVE_ARRAY;
        $elements = [];
        for ($i = 0; $i < $array->nextFreeElement; ++$i) {
            $slot = $context->builder->inBoundsGep($array->value, $zero, $sizeT->constInt($i, false));
            if (JITVariable::TYPE_STRING === $elemType) {
                $elements[] = new JITVariable($context, $elemType, JITVariable::KIND_VARIABLE, $slot);
            } else {
                $elements[] = new JITVariable(
                    $context,
                    $elemType,
                    JITVariable::KIND_VALUE,
                    $context->builder->load($slot)
                );
            }
        }

        return $elements;
    }

    private static function formatFromHashtable(Context $context, Value $fmt, Value $ht): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $valueTy = $context->getTypeFromString('__value__');
        $count = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $ht
        );
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $numArgs = $context->builder->intCast($count, $i64);
        $elemSize = $context->builder->ptrToInt(
            $context->builder->gep(
                $valueTy->pointerType(0)->constNull(),
                $context->getTypeFromString('int32')->constInt(1, false)
            ),
            $sizeT
        );
        $argvBytes = $context->builder->mul($elemSize, $count);
        $argvRaw = $context->builder->call(
            $context->lookupFunction('__mm__malloc'),
            $argvBytes
        );
        $argvPtr = $context->builder->pointerCast(
            $argvRaw,
            $context->getTypeFromString('__value__*')
        );

        $idxSlot = $context->builder->alloca($sizeT, 1, 'vfprintf_argv_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'vfprintf_argv_head');
        $body = BasicBlockHelper::append($context, 'vfprintf_argv_body');
        $advance = BasicBlockHelper::append($context, 'vfprintf_argv_advance');
        $done = BasicBlockHelper::append($context, 'vfprintf_argv_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $dest = $context->builder->inBoundsGEP($argvPtr, $context->builder->intCast($idx, $i64));
        $entry = self::valueEntryAt($context, $ht, $idx);
        JitValueBox::copyFromPointer($context, $dest, $entry);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $result = $context->builder->call(
            $context->lookupFunction('__compiler_sprintf'),
            $fmt,
            $numArgs,
            $argvPtr
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $argvRaw);

        return $result;
    }

    private static function valueEntryAt(Context $context, Value $ht, Value $index): Value
    {
        $map = $context->structFieldMap['__hashtable__'];
        $values = $context->builder->load(
            $context->builder->structGep($ht, $map['values'])
        );

        return $context->builder->inBoundsGep($values, $index);
    }

    /** @return Value */
    public static function loadArgsArray(Context $context, JITVariable $arg, string $fn = 'vprintf'): JITVariable
    {
        $valuesArgNum = 'vfprintf' === $fn ? 3 : 2;
        JitVsprintf::requireValuesArrayArg($context, $arg, $fn, $valuesArgNum);
        if ($arg->type & JITVariable::IS_NATIVE_ARRAY) {
            return $arg;
        }
        if (JITVariable::TYPE_HASHTABLE === $arg->type) {
            return $arg;
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return new JITVariable(
                $context,
                JITVariable::TYPE_HASHTABLE,
                JITVariable::KIND_VALUE,
                HashTableHelper::readHashtableFromValueBox($context, $arg)
            );
        }

        throw new \LogicException($fn.'() args must be an array in this compiler build');
    }
}
