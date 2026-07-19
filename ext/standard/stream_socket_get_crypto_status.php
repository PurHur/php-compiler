<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * stream_socket_get_crypto_status() — PHP 8.6 OpenSSL WANT_READ/WRITE (#21021).
 *
 * php-src: ext/standard/streamsfuncs.c — PHP_FUNCTION(stream_socket_get_crypto_status)
 */
final class stream_socket_get_crypto_status extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_socket_get_crypto_status');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                \sprintf(
                    'stream_socket_get_crypto_status() expects exactly 1 argument, %d given',
                    \count($frame->calledArgs)
                )
            );
        }
        $handle = VmStreamArg::requireStreamHandle(
            $frame->calledArgs[0]->resolveIndirect(),
            'stream_socket_get_crypto_status',
            1
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmStreamSocketGetCryptoStatus::invoke($handle));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'stream_socket_get_crypto_status() is VM-only in this compiler build (issue #21021)'
        );
    }
}
