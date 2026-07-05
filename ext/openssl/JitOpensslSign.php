<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\OpensslSignCrypto;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM helpers for openssl_sign()/openssl_verify() — OpensslSignJitHelper PHP (#3324). */
final class JitOpensslSign
{
    private static int $blockSerial = 0;

    public static function sign(
        Context $context,
        JITVariable $data,
        JITVariable $signatureOut,
        JITVariable $privateKey,
        ?JITVariable $algorithm = null
    ): Value {
        OpensslSignCrypto::ensureLinked($context);

        $algo = self::lowerAlgorithm($context, $algorithm, 'openssl_sign(): Argument #4 ($algorithm)');
        $digest = $context->builder->call(
            $context->lookupFunction('__compiler_openssl_sign'),
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $data, 'openssl_sign', 0, 'data'),
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $privateKey, 'openssl_sign', 2, 'private_key'),
            $algo
        );

        $strPtr = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $digest, $strPtr->constNull());

        $id = (string) (++self::$blockSerial);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'ossl_sign_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'ossl_sign_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'ossl_sign_done_'.$id);
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::valuePtrFromVariable($context, $signatureOut),
            $digest
        );
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(true));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    public static function verify(
        Context $context,
        JITVariable $data,
        JITVariable $signature,
        JITVariable $publicKey,
        ?JITVariable $algorithm = null
    ): Value {
        OpensslSignCrypto::ensureLinked($context);

        $algo = self::lowerAlgorithm($context, $algorithm, 'openssl_verify(): Argument #4 ($algorithm)');
        $resultI32 = $context->builder->call(
            $context->lookupFunction('__compiler_openssl_verify'),
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $data, 'openssl_verify', 0, 'data'),
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $signature, 'openssl_verify', 1, 'signature'),
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $publicKey, 'openssl_verify', 2, 'public_key'),
            $algo
        );

        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong(
            $context,
            $slot,
            $context->builder->sext($resultI32, $context->getTypeFromString('int64'))
        );

        return JitValueBox::pointer($context, $slot);
    }

    private static function lowerAlgorithm(Context $context, ?JITVariable $algorithm, string $label = 'algorithm'): Value
    {
        if (null === $algorithm) {
            return $context->getTypeFromString('int64')->constInt(OpensslConstants::OPENSSL_ALGO_SHA1, false);
        }

        return JitLongArg::lower($context, $algorithm, $label);
    }
}
