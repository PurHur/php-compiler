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

/** @internal EVP digest NestedJIT leaf for HashCryptoJitHelper (#21026). */
final class phpc_hash_crypto_hash extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_hash_crypto_hash');
    }

    public function execute(Frame $frame): void
    {
        if (3 !== \count($frame->calledArgs)) {
            throw new \LogicException('phpc_hash_crypto_hash() expects exactly 3 arguments');
        }
        $algo = $frame->calledArgs[0]->resolveIndirect()->toString();
        $data = $frame->calledArgs[1]->resolveIndirect()->toString();
        $raw = $frame->calledArgs[2]->resolveIndirect()->toBool();
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmHash::hash($algo, $data, $raw));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (3 !== \count($args)) {
            throw new \LogicException('phpc_hash_crypto_hash() expects exactly 3 arguments');
        }
        JitHashCryptoKernel::ensureEvpLeaves($context);
        $algo = JitStringBuiltinArg::lower($context, $args[0], 'phpc_hash_crypto_hash', 0, 'algo');
        $data = JitStringBuiltinArg::lower($context, $args[1], 'phpc_hash_crypto_hash', 1, 'data');
        $raw = HashCryptoKernelArgs::lowerBoolI32($context, $args[2]);

        return $context->builder->call(
            $context->lookupFunction(JitHashCryptoKernel::EVP_HASH),
            $algo,
            $data,
            $raw
        );
    }
}
