<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringHashCrypto;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM helpers for hash() / hash_hmac() — PHP JIT via StringHashCryptoJit (issue #7060). */
final class JitHash
{
    private static int $blockSerial = 0;

    public static function hash(Context $context, Value $algo, Value $data, Value $raw): Value
    {
        self::ensureHashCryptoLinked($context);
        $rawI32 = $context->builder->zExt($raw, $context->getTypeFromString('int32'));

        return self::digestToValue($context, $context->builder->call(
            $context->lookupFunction('__compiler_hash'),
            $algo,
            $data,
            $rawI32
        ));
    }

    public static function hashHmac(Context $context, Value $algo, Value $data, Value $key, Value $raw): Value
    {
        self::ensureHashCryptoLinked($context);
        $rawI32 = $context->builder->zExt($raw, $context->getTypeFromString('int32'));

        return self::digestToValue($context, $context->builder->call(
            $context->lookupFunction('__compiler_hash_hmac'),
            $algo,
            $data,
            $key,
            $rawI32
        ));
    }

    public static function hashPbkdf2(
        Context $context,
        Value $algo,
        Value $password,
        Value $salt,
        Value $iterations,
        Value $length,
        Value $raw
    ): Value {
        self::ensureHashCryptoLinked($context);
        $rawI32 = $context->builder->zExt($raw, $context->getTypeFromString('int32'));

        return self::digestToValue($context, $context->builder->call(
            $context->lookupFunction('__compiler_hash_pbkdf2'),
            $algo,
            $password,
            $salt,
            $iterations,
            $length,
            $rawI32
        ));
    }

    public static function hashHkdf(
        Context $context,
        Value $algo,
        Value $key,
        Value $length,
        Value $info,
        Value $salt
    ): Value {
        self::ensureHashCryptoLinked($context);

        return self::digestToValue($context, $context->builder->call(
            $context->lookupFunction('__compiler_hash_hkdf'),
            $algo,
            $key,
            $length,
            $info,
            $salt
        ));
    }

    public static function equals(Context $context, Value $known, Value $user): Value
    {
        self::ensureHashCryptoLinked($context);
        $i32 = $context->getTypeFromString('int32');
        $result = $context->builder->call(
            $context->lookupFunction('__compiler_hash_equals'),
            $known,
            $user
        );

        return $context->builder->icmp(
            Builder::INT_NE,
            $result,
            $i32->constInt(0, false)
        );
    }

    public static function digestToValue(Context $context, Value $digest): Value
    {
        $id = (string) (++self::$blockSerial);
        $strPtr = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $digest, $strPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'hash_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'hash_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'hash_done_'.$id);
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

    private static function ensureHashCryptoLinked(Context $context): void
    {
        $savedInsert = null;
        try {
            $savedInsert = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }
        StringHashCrypto::ensureLinked($context);
        if (null !== $savedInsert) {
            $context->builder->positionAtEnd($savedInsert);
        }
    }
}
