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

/** @internal EVP PBKDF2 NestedJIT leaf for HashCryptoJitHelper (#21026). */
final class phpc_hash_crypto_pbkdf2 extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_hash_crypto_pbkdf2');
    }

    public function execute(Frame $frame): void
    {
        if (6 !== \count($frame->calledArgs)) {
            throw new \LogicException('phpc_hash_crypto_pbkdf2() expects exactly 6 arguments');
        }
        $algo = $frame->calledArgs[0]->resolveIndirect()->toString();
        $password = $frame->calledArgs[1]->resolveIndirect()->toString();
        $salt = $frame->calledArgs[2]->resolveIndirect()->toString();
        $iterations = (int) $frame->calledArgs[3]->resolveIndirect()->toInt();
        $length = (int) $frame->calledArgs[4]->resolveIndirect()->toInt();
        $raw = $frame->calledArgs[5]->resolveIndirect()->toBool();
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmHash::hashPbkdf2($algo, $password, $salt, $iterations, $length, $raw));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (6 !== \count($args)) {
            throw new \LogicException('phpc_hash_crypto_pbkdf2() expects exactly 6 arguments');
        }
        JitHashCryptoKernel::ensureEvpLeaves($context);
        $algo = JitStringBuiltinArg::lower($context, $args[0], 'phpc_hash_crypto_pbkdf2', 0, 'algo');
        $password = JitStringBuiltinArg::lower($context, $args[1], 'phpc_hash_crypto_pbkdf2', 1, 'password');
        $salt = JitStringBuiltinArg::lower($context, $args[2], 'phpc_hash_crypto_pbkdf2', 2, 'salt');
        $iterations = HashCryptoKernelArgs::lowerInt64($context, $args[3]);
        $length = HashCryptoKernelArgs::lowerInt64($context, $args[4]);
        $raw = HashCryptoKernelArgs::lowerBoolI32($context, $args[5]);

        return $context->builder->call(
            $context->lookupFunction(JitHashCryptoKernel::EVP_PBKDF2),
            $algo,
            $password,
            $salt,
            $iterations,
            $length,
            $raw
        );
    }
}
