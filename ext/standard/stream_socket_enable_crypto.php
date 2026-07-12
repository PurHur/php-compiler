<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * stream_socket_enable_crypto() — TLS on stream sockets (#4610).
 *
 * php-src: ext/standard/streamsfuncs.c — PHP_FUNCTION(stream_socket_enable_crypto)
 */
final class stream_socket_enable_crypto extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_socket_enable_crypto');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'stream_socket_enable_crypto', 2, 6);
        $handle = VmStreamArg::requireStreamHandle(
            $frame->calledArgs[0]->resolveIndirect(),
            'stream_socket_enable_crypto',
            1
        );
        if (InternalStrictArg::isCallerStrict($frame)) {
            $enable = InternalStrictArg::requireBool($frame, 1, 'stream_socket_enable_crypto', 'enable')->toBool();
        } else {
            $enable = VmMath::parseBoolBuiltinArg(
                $frame->calledArgs[1]->resolveIndirect(),
                'stream_socket_enable_crypto',
                2,
                'enable'
            );
        }

        $cryptoMethod = null;
        if (\count($frame->calledArgs) >= 3) {
            $cryptoMethod = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[2]->resolveIndirect(),
                'stream_socket_enable_crypto',
                3,
                'crypto_method'
            );
        }

        $sessionHandle = null;
        if (\count($frame->calledArgs) >= 4) {
            $sessionHandle = VmStreamArg::requireStreamHandle(
                $frame->calledArgs[3]->resolveIndirect(),
                'stream_socket_enable_crypto',
                4
            );
        }

        $capturePeerCert = null;
        if (\count($frame->calledArgs) >= 5) {
            $capturePeerCert = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[4]->resolveIndirect(),
                'stream_socket_enable_crypto',
                5,
                'capture_peer_cert'
            );
        }

        $passphrase = null;
        if (\count($frame->calledArgs) >= 6) {
            $passphrase = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[5]->resolveIndirect(),
                'stream_socket_enable_crypto',
                6,
                'passphrase'
            );
        }

        if (null === $frame->returnVar) {
            return;
        }

        $frame->returnVar->bool(
            VmFs::streamSocketEnableCrypto(
                $handle,
                $enable,
                $cryptoMethod,
                $sessionHandle,
                $capturePeerCert,
                $passphrase
            )
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 6) {
            throw new \LogicException('stream_socket_enable_crypto() requires two to six arguments in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        JitInternalStrictArg::requireBool($context, $args[1], 'stream_socket_enable_crypto', 'enable', 2);
        $enable = $context->builder->zExt(
            JitBoolArg::lower($context, $args[1], 'stream_socket_enable_crypto(): Argument #2 ($enable)'),
            $i64
        );
        $hasCryptoMethod = $i64->constInt($argc >= 3 ? 1 : 0, false);
        $cryptoMethod = $argc >= 3
            ? JitLongArg::lower($context, $args[2], 'stream_socket_enable_crypto(): Argument #3 ($crypto_method)')
            : $i64->constInt(0, false);

        return JitStreamEnableCrypto::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'stream_socket_enable_crypto() stream'),
                $i64
            ),
            $enable,
            $hasCryptoMethod,
            $cryptoMethod
        );
    }
}
