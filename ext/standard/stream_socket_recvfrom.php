<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * stream_socket_recvfrom() — receive from socket stream (issue #21007, re-#6043).
 *
 * php-src: ext/standard/streamsfuncs.c — PHP_FUNCTION(stream_socket_recvfrom)
 */
final class stream_socket_recvfrom extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_socket_recvfrom');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(
                $argc < 2
                    ? 'stream_socket_recvfrom() expects at least 2 arguments, '.$argc.' given'
                    : 'stream_socket_recvfrom() expects at most 4 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $handle = VmStreamArg::requireStreamHandle(
            $frame->calledArgs[0]->resolveIndirect(),
            'stream_socket_recvfrom',
            1
        );
        $length = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[1]->resolveIndirect(),
            'stream_socket_recvfrom',
            2,
            'length'
        );
        if ($length <= 0) {
            throw new \ValueError('stream_socket_recvfrom(): Argument #2 ($length) must be greater than 0');
        }

        $flags = 0;
        if ($argc >= 3) {
            $flags = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[2]->resolveIndirect(),
                'stream_socket_recvfrom',
                3,
                'flags'
            );
        }

        $wantAddress = $argc >= 4;
        if ($wantAddress) {
            $nullOut = new Variable();
            $nullOut->null();
            $frame->calledArgs[3]->byRefTarget()->copyFrom($nullOut);
        }

        [$buf, $address] = VmStreamSocketRecvfrom::recvfrom($handle, $length, $flags, $wantAddress);
        if ($wantAddress && \is_string($address)) {
            $addrOut = new Variable();
            $addrOut->string($address);
            $frame->calledArgs[3]->byRefTarget()->copyFrom($addrOut);
        }

        if (false === $buf) {
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->string($buf);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'stream_socket_recvfrom() is not supported for JIT/AOT in this compiler build (issue #21007)'
        );
    }
}
