<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\VM\HashVmRuntimeSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Native thin-AOT hash() for crypto digests — no NestedJIT of the hash crypto helper (#36388).
 *
 * Stale helper-runtime TUs still NestedJIT {@code __compiler_hash} (leaky FUNCCALL
 * temps under thin AOT — OOM at a few hundred sha256 digests). Call sites use
 * {@code phpc_hash_r1} so this body always wins — peer {@see Md5Runtime} /
 * {@see Sha1Runtime}.
 *
 * Digest via libcrypto EVP leaf {@code __phpc_hc_evp_hash} (via
 * {@see HashVmRuntimeSupport}; no lib→ext import). Unknown algo → null
 * {@code __string__*} (caller ValueError). NonCrypto names (crc32/adler32/fnv)
 * are rejected by EVP today; NestedJIT {@code __compiler_hash} for those remains
 * a follow-up (pre-existing abort on tip for issue_34828).
 *
 * php-src: ext/hash/hash.c — PHP_FUNCTION(hash)
 */
final class HashRuntime
{
    public const ABI = 'phpc_hash_r1';

    public const BRIDGE_ENTRY = 'hash_r1_bridge_entry';

    /** Same symbol as {@see \PHPCompiler\ext\hash\JitHashCryptoKernel::EVP_HASH}. */
    private const EVP_HASH = '__phpc_hc_evp_hash';

    private static int $seq = 0;

    /**
     * Emit full hash bridge body (builder already at entry). Ends with returnValue.
     *
     * Params: (algo, data, raw_i32) — raw non-zero → binary digest; else hex.
     * EVP miss / unavailable → null (caller ValueError or NonCrypto legacy path).
     */
    public static function emitBridgeBody(Context $context, LlvmFunction $fn): void
    {
        ++self::$seq;
        $tag = 'hash_'.(string) self::$seq;

        HashVmRuntimeSupport::ensureEvpLeaves($context);

        $algo = $fn->getParam(0);
        $data = $fn->getParam(1);
        $rawI32 = $fn->getParam(2);

        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();

        $evp = $context->module->getNamedFunction(self::EVP_HASH);
        if (null === $evp || $evp->countBasicBlocks() < 1) {
            $context->builder->returnValue($nullStr);

            return;
        }

        $context->registerFunction(self::EVP_HASH, $evp);
        $digest = $context->builder->call($evp, $algo, $data, $rawI32);

        $evpNullBb = $fn->appendBasicBlock($tag.'_evp_null');
        $okBb = $fn->appendBasicBlock($tag.'_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $digest, $nullStr),
            $evpNullBb,
            $okBb
        );

        $context->builder->positionAtEnd($evpNullBb);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($okBb);
        $context->builder->returnValue($digest);
    }
}
