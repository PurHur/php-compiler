<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\ext\standard\VmHash;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** @internal EVP HKDF NestedJIT leaf for HashCryptoJitHelper (#21026). */
final class phpc_hash_crypto_hkdf extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_hash_crypto_hkdf');
    }

    public function execute(Frame $frame): void
    {
        if (5 !== \count($frame->calledArgs)) {
            throw new \LogicException('phpc_hash_crypto_hkdf() expects exactly 5 arguments');
        }
        $algo = $frame->calledArgs[0]->resolveIndirect()->toString();
        $key = $frame->calledArgs[1]->resolveIndirect()->toString();
        $length = (int) $frame->calledArgs[2]->resolveIndirect()->toInt();
        $info = $frame->calledArgs[3]->resolveIndirect()->toString();
        $salt = $frame->calledArgs[4]->resolveIndirect()->toString();
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmHash::hashHkdf($algo, $key, $length, $info, $salt));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (5 !== \count($args)) {
            throw new \LogicException('phpc_hash_crypto_hkdf() expects exactly 5 arguments');
        }
        JitHashCryptoKernel::ensureEvpLeaves($context);
        $algo = JitStringBuiltinArg::lower($context, $args[0], 'phpc_hash_crypto_hkdf', 0, 'algo');
        $key = JitStringBuiltinArg::lower($context, $args[1], 'phpc_hash_crypto_hkdf', 1, 'key');
        $length = HashCryptoKernelArgs::lowerInt64($context, $args[2]);
        $info = JitStringBuiltinArg::lower($context, $args[3], 'phpc_hash_crypto_hkdf', 3, 'info');
        $salt = JitStringBuiltinArg::lower($context, $args[4], 'phpc_hash_crypto_hkdf', 4, 'salt');

        return $context->builder->call(
            $context->lookupFunction(JitHashCryptoKernel::EVP_HKDF),
            $algo,
            $key,
            $length,
            $info,
            $salt
        );
    }
}
