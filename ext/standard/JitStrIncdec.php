<?php

declare(strict_types=1);

/**
 * LLVM JIT/AOT helpers for str_increment() / str_decrement() (issues #3102, #3726).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringStrIncdec;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitStrIncdec
{
    private const INCREMENT_EMPTY = 'str_increment(): Argument #1 ($string) must not be empty';

    private const DECREMENT_EMPTY = 'str_decrement(): Argument #1 ($string) must not be empty';

    private static int $blockSerial = 0;

    public static function increment(Context $context, Value $input): Value
    {
        StringStrIncdec::ensureLinked($context);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);

        self::emitEmptyGuard($context, $input, self::INCREMENT_EMPTY);

        $dataPtr = self::stringDataPtr($context, $input);
        $result = $context->builder->call(
            $context->lookupFunction('phpc_str_increment'),
            $dataPtr
        );

        return self::guardResult($context, $result);
    }

    public static function decrement(Context $context, Value $input): Value
    {
        StringStrIncdec::ensureLinked($context);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);

        self::emitEmptyGuard($context, $input, self::DECREMENT_EMPTY);

        $dataPtr = self::stringDataPtr($context, $input);
        $result = $context->builder->call(
            $context->lookupFunction('phpc_str_decrement'),
            $dataPtr
        );

        return self::guardResult($context, $result);
    }

    private static function emitEmptyGuard(Context $context, Value $input, string $message): void
    {
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load(
            $context->builder->structGep($input, $map['length'])
        );
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);

        $id = (string) (++self::$blockSerial);
        $okBlock = BasicBlockHelper::append($context, 'strincdec_len_ok_'.$id);
        $errBlock = BasicBlockHelper::append($context, 'strincdec_len_err_'.$id);
        $context->builder->branchIf($isEmpty, $errBlock, $okBlock);

        $context->builder->positionAtEnd($errBlock);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($okBlock);
    }

    private static function guardResult(Context $context, Value $result): Value
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $result, $strPtr->constNull());

        $id = (string) (++self::$blockSerial);
        $okBlock = BasicBlockHelper::append($context, 'strincdec_ok_'.$id);
        $errBlock = BasicBlockHelper::append($context, 'strincdec_err_'.$id);
        $context->builder->branchIf($isNull, $errBlock, $okBlock);

        $context->builder->positionAtEnd($errBlock);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($okBlock);

        return $result;
    }

    private static function stringDataPtr(Context $context, Value $input): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->pointerCast(
            $context->builder->structGep($input, $map['value']),
            $context->getTypeFromString('int8*')
        );
    }
}
