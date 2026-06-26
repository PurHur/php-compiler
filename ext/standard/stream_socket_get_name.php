<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * stream_socket_get_name() — bound socket / peer address (issue #12223).
 *
 * php-src: ext/standard/streamsfuncs.c — PHP_FUNCTION(stream_socket_get_name)
 */
final class stream_socket_get_name extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_socket_get_name');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'stream_socket_get_name() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $handle = VmStreamArg::requireStreamHandle(
            $frame->calledArgs[0]->resolveIndirect(),
            'stream_socket_get_name',
            1
        );
        $wantPeer = VmMath::parseBoolBuiltinArg(
            $frame->calledArgs[1]->resolveIndirect(),
            'stream_socket_get_name',
            2,
            'want_peer'
        );

        $result = VmStreamSocketGetName::getName($handle, $wantPeer);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \ArgumentCountError(
                'stream_socket_get_name() expects exactly 2 arguments, '.\count($args).' given'
            );
        }

        return JitStreamSocketGetName::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'stream_socket_get_name() stream'),
                $context->getTypeFromString('int64')
            ),
            JitBoolArg::lower($context, $args[1], 'stream_socket_get_name() want_peer')
        );
    }
}
