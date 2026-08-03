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
 * @internal EVP encrypt NestedJIT leaf for OpensslEncryptJitHelper (#27265).
 *
 * Args: data, cipher_algo, key, iv, zero_padding (0/1). Returns raw ciphertext or empty on failure.
 */
final class phpc_openssl_cipher_encrypt extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_openssl_cipher_encrypt');
    }

    public function execute(Frame $frame): void
    {
        if (5 !== \count($frame->calledArgs)) {
            throw new \LogicException('phpc_openssl_cipher_encrypt() expects exactly 5 arguments');
        }
        $data = $frame->calledArgs[0]->resolveIndirect()->toString();
        $cipher = $frame->calledArgs[1]->resolveIndirect()->toString();
        $key = $frame->calledArgs[2]->resolveIndirect()->toString();
        $iv = $frame->calledArgs[3]->resolveIndirect()->toString();
        $zeroPadding = 0 !== $frame->calledArgs[4]->resolveIndirect()->toInt();
        $result = VmOpensslCipherNative::encrypt($data, $cipher, $key, $iv, $zeroPadding);
        if (null !== $frame->returnVar) {
            if (false === $result) {
                $frame->returnVar->null();
            } else {
                $frame->returnVar->string($result['ciphertext']);
            }
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (5 !== \count($args)) {
            throw new \LogicException('phpc_openssl_cipher_encrypt() expects exactly 5 arguments');
        }
        JitOpensslCipherKernel::ensureEvpLeaves($context);
        $data = JitStringBuiltinArg::lower($context, $args[0], 'phpc_openssl_cipher_encrypt', 0, 'data');
        $cipher = JitStringBuiltinArg::lower($context, $args[1], 'phpc_openssl_cipher_encrypt', 1, 'cipher_algo');
        $key = JitStringBuiltinArg::lower($context, $args[2], 'phpc_openssl_cipher_encrypt', 2, 'key');
        $iv = JitStringBuiltinArg::lower($context, $args[3], 'phpc_openssl_cipher_encrypt', 3, 'iv');
        $zero = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[4], 'phpc_openssl_cipher_encrypt(): Argument #5 ($zero_padding)'),
            $context->getTypeFromString('int32')
        );
        $raw = \count($args) > 5
            ? $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[5], 'phpc_openssl_cipher_encrypt(): Argument #6 ($raw_output)'),
                $context->getTypeFromString('int32')
            )
            : $context->getTypeFromString('int32')->constInt(1, false);

        return $context->builder->call(
            $context->lookupFunction(JitOpensslCipherKernel::EVP_ENCRYPT),
            $data,
            $cipher,
            $key,
            $iv,
            $zero,
            $raw
        );
    }
}
