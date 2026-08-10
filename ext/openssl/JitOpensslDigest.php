<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\OpensslDigestCrypto;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\JitHash;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM helpers for openssl_digest() (#21081).
 *
 * Known compile-time method names lower via {@see JitHash} / `__compiler_hash` (AOT-green).
 * Unknown / dynamic method names use NestedJIT {@see OpensslDigestJitHelper} (VM SSOT).
 */
final class JitOpensslDigest
{
    private static int $blockSerial = 0;

    public static function digest(
        Context $context,
        JITVariable $data,
        JITVariable $method,
        ?JITVariable $rawOutput = null
    ): Value {
        // Soft-null $data on 8.4 — Zend deprecate+coerce (#21517, reverts #20207 TypeError).
        $dataVal = JitStringBuiltinArg::lowerTrimFamilyString($context, $data, 'openssl_digest', 0, 'data');
        $rawI1 = null === $rawOutput
            ? $context->getTypeFromString('int1')->constInt(0, false)
            : JitBoolArg::lower($context, $rawOutput, 'openssl_digest(): Argument #3 ($raw_output)');

        if (null !== $method->compileTimeString) {
            $algo = \strtolower($method->compileTimeString);
            if (OpensslCipherRegistry::digestImplemented($algo)) {
                // constantStringFromString → __string__**; load to __string__* for __compiler_hash.
                $methodVal = $context->builder->load($context->constantStringFromString($algo));

                return JitHash::hash($context, $methodVal, $dataVal, $rawI1);
            }
            // Unknown compile-time algo: NestedJIT helper emits warning + false (#21081).
            return self::digestViaNestedJit($context, $dataVal, $method, $rawI1);
        }

        return self::digestViaNestedJit($context, $dataVal, $method, $rawI1);
    }

    private static function digestViaNestedJit(
        Context $context,
        Value $dataVal,
        JITVariable $method,
        Value $rawI1
    ): Value {
        OpensslDigestCrypto::ensureLinked($context);
        $rawI64 = $context->builder->zExt($rawI1, $context->getTypeFromString('int64'));
        $methodVal = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $method,
            'openssl_digest',
            1,
            'digest_algo'
        );
        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_openssl_digest'),
            $dataVal,
            $methodVal,
            $rawI64
        );

        return self::stringOrFalse($context, $raw, 'ossl_digest');
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
