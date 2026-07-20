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

/** LLVM helpers for openssl_encrypt()/openssl_decrypt() — OpensslEncryptJitHelper PHP (#21065, AEAD #21135). */
final class JitOpensslEncrypt
{
    private static int $blockSerial = 0;

    public static function encrypt(
        Context $context,
        JITVariable $data,
        JITVariable $cipherAlgo,
        JITVariable $passphrase,
        ?JITVariable $options = null,
        ?JITVariable $iv = null,
        ?JITVariable $tagOut = null,
        ?JITVariable $aad = null,
        ?JITVariable $tagLength = null
    ): Value {
        OpensslEncryptCrypto::ensureLinked($context);

        $optionsVal = null === $options
            ? $context->getTypeFromString('int64')->constInt(0, false)
            : JitLongArg::lower($context, $options, 'openssl_encrypt(): Argument #4 ($options)');
        $ivVal = null === $iv
            ? $context->constantStringFromString('')
            : JitStringBuiltinArg::lowerStrictOrCoercible($context, $iv, 'openssl_encrypt', 4, 'iv');
        $aadVal = null === $aad
            ? $context->constantStringFromString('')
            : JitStringBuiltinArg::lowerStrictOrCoercible($context, $aad, 'openssl_encrypt', 6, 'aad');
        $tagLenVal = null === $tagLength
            ? $context->getTypeFromString('int64')->constInt(16, false)
            : JitLongArg::lower($context, $tagLength, 'openssl_encrypt(): Argument #8 ($tag_length)');
        $tagModeVal = $context->getTypeFromString('int64')->constInt(null === $tagOut ? 0 : 1, false);

        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_openssl_encrypt'),
            JitStringBuiltinArg::lowerTrimFamilyString($context, $data, 'openssl_encrypt', 0, 'data'),
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $cipherAlgo, 'openssl_encrypt', 1, 'cipher_algo'),
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $passphrase, 'openssl_encrypt', 2, 'passphrase'),
            $optionsVal,
            $ivVal,
            $aadVal,
            $tagLenVal,
            $tagModeVal
        );

        return self::stringOrFalse($context, $raw, 'ossl_encrypt', $tagOut);
    }

    public static function decrypt(
        Context $context,
        JITVariable $data,
        JITVariable $cipherAlgo,
        JITVariable $passphrase,
        ?JITVariable $options = null,
        ?JITVariable $iv = null,
        ?JITVariable $tag = null,
        ?JITVariable $aad = null
    ): Value {
        OpensslEncryptCrypto::ensureLinked($context);

        $optionsVal = null === $options
            ? $context->getTypeFromString('int64')->constInt(0, false)
            : JitLongArg::lower($context, $options, 'openssl_decrypt(): Argument #4 ($options)');
        $ivVal = null === $iv
            ? $context->constantStringFromString('')
            : JitStringBuiltinArg::lowerStrictOrCoercible($context, $iv, 'openssl_decrypt', 4, 'iv');
        $tagVal = null === $tag
            ? $context->constantStringFromString('')
            : JitStringBuiltinArg::lowerStrictOrCoercible($context, $tag, 'openssl_decrypt', 5, 'tag');
        $aadVal = null === $aad
            ? $context->constantStringFromString('')
            : JitStringBuiltinArg::lowerStrictOrCoercible($context, $aad, 'openssl_decrypt', 6, 'aad');

        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_openssl_decrypt'),
            JitStringBuiltinArg::lowerTrimFamilyString($context, $data, 'openssl_decrypt', 0, 'data'),
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $cipherAlgo, 'openssl_decrypt', 1, 'cipher_algo'),
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $passphrase, 'openssl_decrypt', 2, 'passphrase'),
            $optionsVal,
            $ivVal,
            $tagVal,
            $aadVal
        );

        return self::stringOrFalse($context, $raw, 'ossl_decrypt', null);
    }

    private static function stringOrFalse(
        Context $context,
        Value $raw,
        string $label,
        ?JITVariable $tagOut
    ): Value {
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
        if (null !== $tagOut) {
            self::emitWritePendingTag($context, $tagOut, $id);
        }
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $raw);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    private static function emitWritePendingTag(Context $context, JITVariable $tagOut, string $id): void
    {
        $i64 = $context->getTypeFromString('int64');
        $isNull = $context->builder->call($context->lookupFunction('__compiler_openssl_encrypt_tag_is_null'));
        $nullBlock = BasicBlockHelper::append($context, 'ossl_encrypt_tag_null_'.$id);
        $strBlock = BasicBlockHelper::append($context, 'ossl_encrypt_tag_str_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'ossl_encrypt_tag_done_'.$id);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $isNull, $i64->constInt(0, false)),
            $nullBlock,
            $strBlock
        );

        $context->builder->positionAtEnd($nullBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::valuePtrFromVariable($context, $tagOut)
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($strBlock);
        $tagStr = $context->builder->call($context->lookupFunction('__compiler_openssl_encrypt_take_tag'));
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::valuePtrFromVariable($context, $tagOut),
            $tagStr
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
    }
}
