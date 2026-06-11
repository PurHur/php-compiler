<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmStreamArg;
use PHPLLVM\Value;

/**
 * socket_import_stream() — adopt stream as Socket (php-src ext/sockets/sockets.c; #6544 helper).
 *
 * Enables socket_atmark compliance when stream_socket_pair is available.
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
        if (null === $frame->returnVar) {
            return;
        }
        if (!\function_exists('socket_import_stream')) {
            $frame->returnVar->bool(false);

            return;
        }

        $handle = VmStreamArg::requireStreamHandle($frame->calledArgs[0], 'socket_import_stream', 1);
        $hostStream = VmFs::lookupResource($handle);
        if (null === $hostStream) {
            $frame->returnVar->bool(false);

            return;
        }
        $hostSocket = @\socket_import_stream($hostStream);
        if (false === $hostSocket || !($hostSocket instanceof \Socket)) {
            $frame->returnVar->bool(false);

            return;
        }
        $wrapped = VmSocket::wrapHost($hostSocket, $frame->vmContext);
        $frame->returnVar->assign($wrapped);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('socket_import_stream() is not implemented for JIT in this compiler build (issue #6544)');
    }
}
