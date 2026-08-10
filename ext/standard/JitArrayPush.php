<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** JIT/AOT guard for array_push() by-reference array argument (#4881). */
final class JitArrayPush
{
    public const BY_REF_ERROR =
        'array_push(): Argument #1 ($array) could not be passed by reference';

    /**
     * @return bool false when compile-time operand is definitely non-array (caller must not lower push)
     */
    public static function requireByRefArrayArg(Context $context, JITVariable $array): bool
    {
        if (JITVariable::TYPE_HASHTABLE === $array->type
            || ($array->type & JITVariable::IS_NATIVE_ARRAY)
        ) {
            return true;
        }
        if (JITVariable::TYPE_VALUE === $array->type) {
            return true;
        }

        self::emitPendingError($context);

        return false;
    }

    /**
     * Runtime guard for boxed array_push() argument #1; merges push count vs 0 on Error (#4881).
     *
     * @param JITVariable[] $values
     */
    public static function pushWithValueBoxGuard(
        Context $context,
        JITVariable $array,
        array $values,
        callable $pushFn
    ): Value {
        $loaded = JitValueBox::valuePtrFromVariable($context, $array);
        $typeField = $context->structFieldMap['__value__']['type'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($loaded, $typeField)
        );
        $i8 = $context->getTypeFromString('int8');
        // Mask IS_REFCOUNTED — HT/array boxes store TYPE_ARRAY|0x80 (#27226, peer HashTableReadLlvm).
        // __value__writeHashtable tags TYPE_HASHTABLE (JIT), not VM TYPE_ARRAY (#27226).
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isVmArray = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_ARRAY & 0x7f, false)
        );
        $isJitHashtable = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JITVariable::TYPE_HASHTABLE & 0x7f, false)
        );
        $isArray = $context->builder->or($isVmArray, $isJitHashtable);
        // Uninitialized boxed property slots (TYPE_UNDEFINED) and null may materialize arrays
        // like HashTableHelper::ensureHashtablePointer (#1086, bootstrap array_value_box).
        $isUndefined = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_UNDEFINED & 0x7f, false)
        );
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_NULL & 0x7f, false)
        );
        $canPush = $context->builder->or(
            $isArray,
            $context->builder->or($isUndefined, $isNull)
        );
        $okBlock = BasicBlockHelper::append($context, 'array_push_vbox_ok');
        $errBlock = BasicBlockHelper::append($context, 'array_push_vbox_err');
        $mergeBlock = BasicBlockHelper::append($context, 'array_push_vbox_merge');
        $context->builder->branchIf($canPush, $okBlock, $errBlock);

        $context->builder->positionAtEnd($errBlock);
        self::emitPendingError($context);
        $errEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($okBlock);
        $result = $pushFn($context, $array, $values);
        $okEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $phi = $context->builder->phi($i64, 'array_push_vbox_phi');
        $phi->addIncoming($zero, $errEnd);
        $phi->addIncoming($result, $okEnd);

        return $phi;
    }

    public static function emitPendingError(Context $context): void
    {
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise($context, self::BY_REF_ERROR);
    }
}
