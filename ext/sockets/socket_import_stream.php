<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmStreamArg;
use PHPLLVM\Value;

/**
 * socket_import_stream() — adopt stream as Socket (php-src ext/sockets/sockets.c; #6203).
 *
 * PHP-owned import via {@see VmSocket::importStreamHandle()}; no host {@see \socket_import_stream()} delegation.
 */
final class socket_import_stream extends Internal
{
    public function __construct()
    {
        parent::__construct('socket_import_stream');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'socket_import_stream() expects exactly 1 argument, '.$argc.' given'
            );
        }

        $handle = VmStreamArg::requireStreamHandle($frame->calledArgs[0], 'socket_import_stream', 1);
        $wrapped = VmSocket::importStreamHandle($handle, $frame->vmContext);
        if (false === $wrapped) {
            VmSockets::triggerWarning($frame, 'socket_import_stream(): Unable to import stream');
            BuiltinExecute::writeReturn(
                $frame,
                static fn (Variable $ret) => $ret->bool(false)
            );

            return;
        }

        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->copyFrom($wrapped)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('socket_import_stream() is not implemented for JIT in this compiler build (issue #6203)');
    }
}
