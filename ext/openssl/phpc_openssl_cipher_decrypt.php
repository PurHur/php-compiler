<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * @internal EVP decrypt NestedJIT leaf for OpensslEncryptJitHelper (#27265).
 *
 * Args: data, cipher_algo, key, iv, zero_padding (0/1). Returns plaintext or null on failure.
 */
final class phpc_openssl_cipher_decrypt extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_openssl_cipher_decrypt');
    }

    public function execute(Frame $frame): void
    {
        if (5 !== \count($frame->calledArgs)) {
            throw new \LogicException('phpc_openssl_cipher_decrypt() expects exactly 5 arguments');
        }
        $data = $frame->calledArgs[0]->resolveIndirect()->toString();
        $cipher = $frame->calledArgs[1]->resolveIndirect()->toString();
        $key = $frame->calledArgs[2]->resolveIndirect()->toString();
        $iv = $frame->calledArgs[3]->resolveIndirect()->toString();
        $zeroPadding = 0 !== $frame->calledArgs[4]->resolveIndirect()->toInt();
        $result = VmOpensslCipherNative::decrypt($data, $cipher, $key, $iv, $zeroPadding);
        if (null !== $frame->returnVar) {
            if (false === $result) {
                $frame->returnVar->null();
            } else {
                $frame->returnVar->string($result);
            }
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (5 !== \count($args)) {
            throw new \LogicException('phpc_openssl_cipher_decrypt() expects exactly 5 arguments');
        }
        JitOpensslCipherKernel::ensureEvpLeaves($context);
        $data = JitStringBuiltinArg::lower($context, $args[0], 'phpc_openssl_cipher_decrypt', 0, 'data');
        $cipher = JitStringBuiltinArg::lower($context, $args[1], 'phpc_openssl_cipher_decrypt', 1, 'cipher_algo');
        $key = JitStringBuiltinArg::lower($context, $args[2], 'phpc_openssl_cipher_decrypt', 2, 'key');
        $iv = JitStringBuiltinArg::lower($context, $args[3], 'phpc_openssl_cipher_decrypt', 3, 'iv');
        $zero = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[4], 'phpc_openssl_cipher_decrypt(): Argument #5 ($zero_padding)'),
            $context->getTypeFromString('int32')
        );
        // Decrypt leaf always returns raw plaintext (raw_output=1).
        $raw = $context->getTypeFromString('int32')->constInt(1, false);

        return $context->builder->call(
            $context->lookupFunction(JitOpensslCipherKernel::EVP_DECRYPT),
            $data,
            $cipher,
            $key,
            $iv,
            $zero,
            $raw
        );
    }
}
