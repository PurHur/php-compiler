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

/** @internal EVP HMAC NestedJIT leaf for HashCryptoJitHelper (#21026). */
final class phpc_hash_crypto_hmac extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_hash_crypto_hmac');
    }

    public function execute(Frame $frame): void
    {
        if (4 !== \count($frame->calledArgs)) {
            throw new \LogicException('phpc_hash_crypto_hmac() expects exactly 4 arguments');
        }
        $algo = $frame->calledArgs[0]->resolveIndirect()->toString();
        $data = $frame->calledArgs[1]->resolveIndirect()->toString();
        $key = $frame->calledArgs[2]->resolveIndirect()->toString();
        $raw = $frame->calledArgs[3]->resolveIndirect()->toBool();
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmHash::hashHmac($algo, $data, $key, $raw));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (4 !== \count($args)) {
            throw new \LogicException('phpc_hash_crypto_hmac() expects exactly 4 arguments');
        }
        JitHashCryptoKernel::ensureEvpLeaves($context);
        $algo = JitStringBuiltinArg::lower($context, $args[0], 'phpc_hash_crypto_hmac', 0, 'algo');
        $data = JitStringBuiltinArg::lower($context, $args[1], 'phpc_hash_crypto_hmac', 1, 'data');
        $key = JitStringBuiltinArg::lower($context, $args[2], 'phpc_hash_crypto_hmac', 2, 'key');
        $raw = HashCryptoKernelArgs::lowerBoolI32($context, $args[3]);

        return $context->builder->call(
            $context->lookupFunction(JitHashCryptoKernel::EVP_HMAC),
            $algo,
            $data,
            $key,
            $raw
        );
    }
}
