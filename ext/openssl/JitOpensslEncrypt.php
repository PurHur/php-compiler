<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\OpensslEncryptCrypto;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM helpers for openssl_encrypt()/openssl_decrypt() — OpensslEncryptJitHelper PHP (#21065). */
final class JitOpensslEncrypt
{
    private static int $blockSerial = 0;

    public static function encrypt(
        Context $context,
        JITVariable $data,
        JITVariable $cipherAlgo,
        JITVariable $passphrase,
        ?JITVariable $options = null,
        ?JITVariable $iv = null
    ): Value {
        OpensslEncryptCrypto::ensureLinked($context);

        $optionsVal = null === $options
            ? $context->getTypeFromString('int64')->constInt(0, false)
            : JitLongArg::lower($context, $options, 'openssl_encrypt(): Argument #4 ($options)');
        $ivVal = null === $iv
            ? $context->constantStringFromString('')
            : JitStringBuiltinArg::lowerStrictOrCoercible($context, $iv, 'openssl_encrypt', 4, 'iv');

        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_openssl_encrypt'),
            JitStringBuiltinArg::lowerZparamStr($context, $data, 'openssl_encrypt', 0, 'data'),
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $cipherAlgo, 'openssl_encrypt', 1, 'cipher_algo'),
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $passphrase, 'openssl_encrypt', 2, 'passphrase'),
            $optionsVal,
            $ivVal
        );

        return self::stringOrFalse($context, $raw, 'ossl_encrypt');
    }

    public static function decrypt(
        Context $context,
        JITVariable $data,
        JITVariable $cipherAlgo,
        JITVariable $passphrase,
        ?JITVariable $options = null,
        ?JITVariable $iv = null
    ): Value {
        OpensslEncryptCrypto::ensureLinked($context);

        $optionsVal = null === $options
            ? $context->getTypeFromString('int64')->constInt(0, false)
            : JitLongArg::lower($context, $options, 'openssl_decrypt(): Argument #4 ($options)');
        $ivVal = null === $iv
            ? $context->constantStringFromString('')
            : JitStringBuiltinArg::lowerStrictOrCoercible($context, $iv, 'openssl_decrypt', 4, 'iv');

        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_openssl_decrypt'),
            JitStringBuiltinArg::lowerZparamStr($context, $data, 'openssl_decrypt', 0, 'data'),
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $cipherAlgo, 'openssl_decrypt', 1, 'cipher_algo'),
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $passphrase, 'openssl_decrypt', 2, 'passphrase'),
            $optionsVal,
            $ivVal
        );

        return self::stringOrFalse($context, $raw, 'ossl_decrypt');
    }

    private static function stringOrFalse(Context $context, Value $raw, string $label): Value
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $failed = $context->builder->icmp(Builder::INT_EQ, $raw, $strPtr->constNull());

        $id = (string) (++self::$blockSerial);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, $label.'_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, $label.'_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, $label.'_done_'.$id);
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $raw);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
