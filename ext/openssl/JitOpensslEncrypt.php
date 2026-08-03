<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\OpensslEncryptCrypto;
use PHPCompiler\JIT\Builtin\StringBase64Decode;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM helpers for openssl_encrypt()/openssl_decrypt() (#21065, AEAD #21135, AOT #27265).
 *
 * Non-AEAD (no &$tag): call {@see JitOpensslCipherKernel} EVP leaves directly — thin-standalone
 * AOT has no FFI, so NestedJIT {@see OpensslEncryptJitHelper} + VmOpensslCipherNative cannot encrypt.
 * AEAD (&$tag): keep NestedJIT helper (JIT in-process FFI).
 */
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

        // AEAD / &$tag still goes through NestedJIT helper (pending-tag writeback).
        if (null !== $tagOut) {
            return self::encryptViaNestedHelper(
                $context,
                $data,
                $cipherAlgo,
                $passphrase,
                $options,
                $iv,
                $tagOut,
                $aad,
                $tagLength
            );
        }

        return self::encryptViaEvpLeaf($context, $data, $cipherAlgo, $passphrase, $options, $iv);
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

        if (null !== $tag) {
            return self::decryptViaNestedHelper(
                $context,
                $data,
                $cipherAlgo,
                $passphrase,
                $options,
                $iv,
                $tag,
                $aad
            );
        }

        return self::decryptViaEvpLeaf($context, $data, $cipherAlgo, $passphrase, $options, $iv);
    }

    private static function encryptViaEvpLeaf(
        Context $context,
        JITVariable $data,
        JITVariable $cipherAlgo,
        JITVariable $passphrase,
        ?JITVariable $options,
        ?JITVariable $iv
    ): Value {
        JitOpensslCipherKernel::ensureEvpLeaves($context);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');

        $optionsVal = null === $options
            ? $i64->constInt(0, false)
            : JitLongArg::lower($context, $options, 'openssl_encrypt(): Argument #4 ($options)');
        $ivVal = null === $iv
            ? $context->builder->load($context->constantStringFromString(''))
            : JitStringBuiltinArg::lowerStrictOrCoercible($context, $iv, 'openssl_encrypt', 4, 'iv');

        $zeroPadBit = $context->builder->bitwiseAnd(
            $optionsVal,
            $i64->constInt(OpensslConstants::OPENSSL_ZERO_PADDING, false)
        );
        $zeroPadI32 = $context->builder->zExt(
            $context->builder->icmp(Builder::INT_NE, $zeroPadBit, $i64->constInt(0, false)),
            $i32
        );
        $rawBit = $context->builder->bitwiseAnd(
            $optionsVal,
            $i64->constInt(OpensslConstants::OPENSSL_RAW_DATA, false)
        );
        $rawI32 = $context->builder->zExt(
            $context->builder->icmp(Builder::INT_NE, $rawBit, $i64->constInt(0, false)),
            $i32
        );

        $raw = $context->builder->call(
            $context->lookupFunction(JitOpensslCipherKernel::EVP_ENCRYPT),
            JitStringBuiltinArg::lowerTrimFamilyString($context, $data, 'openssl_encrypt', 0, 'data'),
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $cipherAlgo, 'openssl_encrypt', 1, 'cipher_algo'),
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $passphrase, 'openssl_encrypt', 2, 'passphrase'),
            $ivVal,
            $zeroPadI32,
            $rawI32
        );

        return self::stringOrFalse($context, $raw, 'ossl_encrypt_leaf', null);
    }

    private static function decryptViaEvpLeaf(
        Context $context,
        JITVariable $data,
        JITVariable $cipherAlgo,
        JITVariable $passphrase,
        ?JITVariable $options,
        ?JITVariable $iv
    ): Value {
        JitOpensslCipherKernel::ensureEvpLeaves($context);
        StringBase64Decode::ensureLinked($context);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');

        $optionsVal = null === $options
            ? $i64->constInt(0, false)
            : JitLongArg::lower($context, $options, 'openssl_decrypt(): Argument #4 ($options)');
        $ivVal = null === $iv
            ? $context->builder->load($context->constantStringFromString(''))
            : JitStringBuiltinArg::lowerStrictOrCoercible($context, $iv, 'openssl_decrypt', 4, 'iv');

        $dataVal = JitStringBuiltinArg::lowerTrimFamilyString($context, $data, 'openssl_decrypt', 0, 'data');
        $payload = self::maybeBase64Decode($context, $dataVal, $optionsVal, 'ossl_decrypt_leaf');

        $zeroPadBit = $context->builder->bitwiseAnd(
            $optionsVal,
            $i64->constInt(OpensslConstants::OPENSSL_ZERO_PADDING, false)
        );
        $zeroPadI32 = $context->builder->zExt(
            $context->builder->icmp(Builder::INT_NE, $zeroPadBit, $i64->constInt(0, false)),
            $i32
        );

        $raw = $context->builder->call(
            $context->lookupFunction(JitOpensslCipherKernel::EVP_DECRYPT),
            $payload,
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $cipherAlgo, 'openssl_decrypt', 1, 'cipher_algo'),
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $passphrase, 'openssl_decrypt', 2, 'passphrase'),
            $ivVal,
            $zeroPadI32,
            $i32->constInt(1, false)
        );

        return self::stringOrFalse($context, $raw, 'ossl_decrypt_leaf', null);
    }

    private static function encryptViaNestedHelper(
        Context $context,
        JITVariable $data,
        JITVariable $cipherAlgo,
        JITVariable $passphrase,
        ?JITVariable $options,
        ?JITVariable $iv,
        JITVariable $tagOut,
        ?JITVariable $aad,
        ?JITVariable $tagLength
    ): Value {
        $optionsVal = null === $options
            ? $context->getTypeFromString('int64')->constInt(0, false)
            : JitLongArg::lower($context, $options, 'openssl_encrypt(): Argument #4 ($options)');
        $ivVal = null === $iv
            ? $context->builder->load($context->constantStringFromString(''))
            : JitStringBuiltinArg::lowerStrictOrCoercible($context, $iv, 'openssl_encrypt', 4, 'iv');
        $aadVal = null === $aad
            ? $context->builder->load($context->constantStringFromString(''))
            : JitStringBuiltinArg::lowerStrictOrCoercible($context, $aad, 'openssl_encrypt', 6, 'aad');
        $tagLenVal = null === $tagLength
            ? $context->getTypeFromString('int64')->constInt(16, false)
            : JitLongArg::lower($context, $tagLength, 'openssl_encrypt(): Argument #8 ($tag_length)');
        $tagModeVal = $context->getTypeFromString('int64')->constInt(1, false);

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

    private static function decryptViaNestedHelper(
        Context $context,
        JITVariable $data,
        JITVariable $cipherAlgo,
        JITVariable $passphrase,
        ?JITVariable $options,
        ?JITVariable $iv,
        JITVariable $tag,
        ?JITVariable $aad
    ): Value {
        $optionsVal = null === $options
            ? $context->getTypeFromString('int64')->constInt(0, false)
            : JitLongArg::lower($context, $options, 'openssl_decrypt(): Argument #4 ($options)');
        $ivVal = null === $iv
            ? $context->builder->load($context->constantStringFromString(''))
            : JitStringBuiltinArg::lowerStrictOrCoercible($context, $iv, 'openssl_decrypt', 4, 'iv');
        $tagVal = JitStringBuiltinArg::lowerStrictOrCoercible($context, $tag, 'openssl_decrypt', 5, 'tag');
        $aadVal = null === $aad
            ? $context->builder->load($context->constantStringFromString(''))
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

    private static function maybeBase64Decode(
        Context $context,
        Value $dataVal,
        Value $optionsVal,
        string $label
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $id = (string) (++self::$blockSerial);

        $rawBit = $context->builder->bitwiseAnd(
            $optionsVal,
            $i64->constInt(OpensslConstants::OPENSSL_RAW_DATA, false)
        );
        $isRaw = $context->builder->icmp(Builder::INT_NE, $rawBit, $i64->constInt(0, false));
        $rawBb = BasicBlockHelper::append($context, $label.'_in_raw_'.$id);
        $b64Bb = BasicBlockHelper::append($context, $label.'_in_b64_'.$id);
        $doneBb = BasicBlockHelper::append($context, $label.'_in_done_'.$id);
        $slot = $context->builder->alloca($strPtr);
        $context->builder->branchIf($isRaw, $rawBb, $b64Bb);

        $context->builder->positionAtEnd($rawBb);
        $context->builder->store($dataVal, $slot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($b64Bb);
        $decoded = $context->builder->call(
            $context->lookupFunction('__compiler_base64_decode'),
            $dataVal
        );
        $context->builder->store($decoded, $slot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $context->builder->load($slot);
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
