<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * stream_socket_accept() — accept connection on server stream (#15346).
 *
 * php-src: ext/standard/streamsfuncs.c — PHP_FUNCTION(stream_socket_accept)
 */
final class stream_socket_accept extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_socket_accept');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(
                'stream_socket_accept() accepts between 1 and 3 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $handle = VmStreamArg::requireStreamHandle(
            $frame->calledArgs[0]->resolveIndirect(),
            'stream_socket_accept',
            1
        );

        $timeout = null;
        if ($argc >= 2) {
            $timeoutVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $timeoutVar->type) {
                $timeout = VmMath::parseDoubleBuiltinArg(
                    $timeoutVar,
                    'stream_socket_accept',
                    2,
                    'timeout'
                );
            }
        }

        [$result, $peername] = VmStreamSocketAccept::accept($handle, $timeout);

        if ($argc >= 3) {
            $peerOut = new Variable(Variable::TYPE_STRING);
            $peerOut->string($peername);
            $frame->calledArgs[2]->byRefTarget()->copyFrom($peerOut);
        }

        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->streamHandle($result, $frame->vmContext);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(
                'stream_socket_accept() accepts between 1 and 3 arguments, '.$argc.' given'
            );
        }

        $handle = JitLongArg::lower($context, $args[0], 'stream_socket_accept() server stream');

        $i64 = $context->getTypeFromString('int64');
        $hasTimeout = $i64->constInt(0, false);
        $timeout = $context->getTypeFromString('double')->constReal(0.0);
        if ($argc >= 2 && JITVariable::TYPE_NULL !== $args[1]->type) {
            $hasTimeout = $i64->constInt(1, false);
            $timeout = JitStreamSocketAccept::lowerTimeoutArg($context, $args[1]);
        }

        return JitStreamSocketAccept::invoke($context, $handle, $hasTimeout, $timeout);
    }
}
