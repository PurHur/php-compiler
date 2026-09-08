<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\VM\HashVmRuntimeSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Native thin-AOT sha1() — no NestedJIT HashCryptoJitHelper (#36388).
 *
 * Stale helper-runtime TUs still NestedJIT {@code __compiler_hash} (leaky FUNCCALL
 * temps under thin AOT). Call sites use {@code phpc_sha1_r1} so this body always
 * wins — peer {@see Md5Runtime} / {@see StrPadRuntime}.
 *
 * Digest via libcrypto EVP leaf {@code __phpc_hc_evp_hash} (registered through
 * {@see HashVmRuntimeSupport}; no lib→ext import). Immortal {@code "sha1"} algo
 * avoids per-call {@code __string__init} leaks.
 *
 * php-src: ext/standard/sha1.c — PHP_FUNCTION(sha1)
 */
final class Sha1Runtime
{
    public const ABI = 'phpc_sha1_r1';

    public const BRIDGE_ENTRY = 'sha1_r1_bridge_entry';

    /** Same symbol as {@see \PHPCompiler\ext\hash\JitHashCryptoKernel::EVP_HASH}. */
    private const EVP_HASH = '__phpc_hc_evp_hash';

    private static int $seq = 0;

    /**
     * Emit full sha1 bridge body (builder already at entry). Ends with returnValue.
     *
     * Params: (data, raw_i32) — raw non-zero → 20-byte binary digest; else hex.
     */
    public static function emitBridgeBody(Context $context, LlvmFunction $fn): void
    {
        ++self::$seq;
        $tag = 'sha1_'.(string) self::$seq;

        HashVmRuntimeSupport::ensureEvpLeaves($context);

        $data = $fn->getParam(0);
        $rawI32 = $fn->getParam(1);
        $algo = $context->builder->load($context->constantStringFromString('sha1'));

        $evp = $context->module->getNamedFunction(self::EVP_HASH);
        if (null === $evp || $evp->countBasicBlocks() < 1) {
            // Hook unset / openssl unavailable — empty owning string (never null).
            $zero = $context->getTypeFromString('int64')->constInt(0, false);
            $context->builder->returnValue(
                $context->builder->call($context->lookupFunction('__string__alloc'), $zero)
            );

            return;
        }

        $context->registerFunction(self::EVP_HASH, $evp);
        $digest = $context->builder->call($evp, $algo, $data, $rawI32);

        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $failBb = $fn->appendBasicBlock($tag.'_evp_null');
        $okBb = $fn->appendBasicBlock($tag.'_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $digest, $nullStr),
            $failBb,
            $okBb
        );

        $context->builder->positionAtEnd($failBb);
        $zero = $context->getTypeFromString('int64')->constInt(0, false);
        $context->builder->returnValue(
            $context->builder->call($context->lookupFunction('__string__alloc'), $zero)
        );

        $context->builder->positionAtEnd($okBb);
        $context->builder->returnValue($digest);
    }
}
