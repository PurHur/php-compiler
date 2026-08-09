<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StreamReadRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for fgets() via __compiler_fgets (issue #1187). */
final class JitFgets
{
    private const LENGTH_ERROR = 'fgets(): Argument #2 ($length) must be greater than 0';

    /**
     * Z_PARAM_LONG_OR_NULL for fgets $length — null ≡ omit (sentinel -1); else must be > 0 (#29506).
     *
     * Explicit 0 / -1 still ValueError (compliance fgets_zero_length).
     */
    public static function lowerNullableLengthArg(Context $context, JITVariable $arg): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $omit = $i64->constInt(-1, true);
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return $omit;
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::lowerBoxedNullableLengthArg($context, $arg);
        }
        $length = JitIntdiv::lowerNullableIntBuiltinArgForCaller($context, $arg, 'fgets', 2, 'length');
        self::emitRuntimeLengthGuard($context, $length);

        return $length;
    }

    /** Runtime null → omit (-1); non-null → coerce + >0 guard (#29506). */
    private static function lowerBoxedNullableLengthArg(Context $context, JITVariable $arg): Value
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $nullTy = $i8->constInt(VmVariable::TYPE_NULL, false);

        $nullBlock = BasicBlockHelper::append($context, 'fgets_len_null');
        $nonNullBlock = BasicBlockHelper::append($context, 'fgets_len_nonnull');
        $mergeBlock = BasicBlockHelper::append($context, 'fgets_len_merge');

        $isNull = $context->builder->icmp(Builder::INT_EQ, $typeByte, $nullTy);
        $context->builder->branchIf($isNull, $nullBlock, $nonNullBlock);

        $context->builder->positionAtEnd($nullBlock);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($nonNullBlock);
        $length = JitIntdiv::lowerNullableIntBuiltinArgForCaller($context, $arg, 'fgets', 2, 'length');
        self::emitRuntimeLengthGuard($context, $length);
        $nonNullEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $i64 = $context->getTypeFromString('int64');
        $phi = $context->builder->phi($i64, 'fgets_len');
        $phi->addIncoming($i64->constInt(-1, true), $nullBlock);
        $phi->addIncoming($length, $nonNullEnd);

        return $phi;
    }

    /** Runtime guard for non-null length (php-src ext/standard/streams.c, #9347). */
    public static function emitRuntimeLengthGuard(Context $context, Value $length): void
    {
        $i64 = $context->getTypeFromString('int64');
        $one = $i64->constInt(1, false);
        $invalid = $context->builder->icmp(Builder::INT_SLT, $length, $one);
        $okBlock = BasicBlockHelper::append($context, 'fgets_len_ok');
        $errBlock = BasicBlockHelper::append($context, 'fgets_len_err');
        $context->builder->branchIf($invalid, $errBlock, $okBlock);
        $context->builder->positionAtEnd($errBlock);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitValueError($context, self::LENGTH_ERROR);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($okBlock);
    }

    /** @return Value
     * (line string, or boolean false on failure/EOF) */
    public static function invoke(Context $context, Value $handleLong, Value $lengthLong): Value
    {
        StreamReadRuntime::ensureLinked($context);
        $contents = $context->builder->call(
            $context->lookupFunction('__compiler_fgets'),
            $handleLong,
            $lengthLong
        );
        $strPtr = $context->getTypeFromString('__string__*');
        $failed = $context->builder->icmp(Builder::INT_EQ, $contents, $strPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'fgets_fail');
        $okBlock = BasicBlockHelper::append($context, 'fgets_ok');
        $doneBlock = BasicBlockHelper::append($context, 'fgets_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $contents
        );
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
