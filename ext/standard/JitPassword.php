<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringPasswordCrypto;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM helpers for password_hash() / password_verify() — PasswordJitHelper PHP via PasswordCryptoRuntime (#6906, #9908). */
final class JitPassword
{
    private static int $blockSerial = 0;

    public static function hash(
        Context $context,
        Value $password,
        Value $algoI64,
        ?JITVariable $options = null
    ): Value {
        StringPasswordCrypto::ensureLinked($context);

        $cost = JitPasswordBcryptCost::lowerFromOptions($context, $options, 'password_hash');
        $digest = $context->builder->call(
            $context->lookupFunction('__compiler_password_hash'),
            $password,
            $algoI64,
            $cost
        );

        return self::nullableStringToValue($context, $digest);
    }

    public static function verify(Context $context, Value $password, Value $hash): Value
    {
        StringPasswordCrypto::ensureLinked($context);

        $i32 = $context->getTypeFromString('int32');

        return $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call(
                $context->lookupFunction('__compiler_password_verify'),
                $password,
                $hash
            ),
            $i32->constInt(0, false)
        );
    }

    public static function crypt(Context $context, Value $password, Value $salt): Value
    {
        StringPasswordCrypto::ensureLinked($context);

        $digest = $context->builder->call(
            $context->lookupFunction('__compiler_crypt'),
            $password,
            $salt
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $digest
        );

        return $ptr;
    }

    private static function nullableStringToValue(Context $context, Value $digest): Value
    {
        $id = (string) (++self::$blockSerial);
        $strPtr = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $digest, $strPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'password_hash_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'password_hash_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'password_hash_done_'.$id);
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $digest
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
