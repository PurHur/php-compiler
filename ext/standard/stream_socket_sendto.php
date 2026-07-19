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
 * stream_socket_sendto() — send on socket stream (issue #21008, re-#6043).
 *
 * php-src: ext/standard/streamsfuncs.c — PHP_FUNCTION(stream_socket_sendto)
 */
final class stream_socket_sendto extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_socket_sendto');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(
                $argc < 2
                    ? 'stream_socket_sendto() expects at least 2 arguments, '.$argc.' given'
                    : 'stream_socket_sendto() expects at most 4 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $handle = VmStreamArg::requireStreamHandle(
            $frame->calledArgs[0]->resolveIndirect(),
            'stream_socket_sendto',
            1
        );
        $data = VmString::coerceTypedStringBuiltinArg(
            $frame->calledArgs[1]->resolveIndirect(),
            'stream_socket_sendto',
            1,
            'data'
        );

        $flags = 0;
        if ($argc >= 3) {
            $flags = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[2]->resolveIndirect(),
                'stream_socket_sendto',
                3,
                'flags'
            );
        }

        $address = null;
        if ($argc >= 4) {
            $addrVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_NULL !== $addrVar->type) {
                $address = VmString::coerceTypedStringBuiltinArg(
                    $addrVar,
                    'stream_socket_sendto',
                    3,
                    'address'
                );
            }
        }

        $n = VmStreamSocketSendto::sendto($handle, $data, $flags, $address);
        if (false === $n) {
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->int($n);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'stream_socket_sendto() is not supported for JIT/AOT in this compiler build (issue #21008)'
        );
    }
}
