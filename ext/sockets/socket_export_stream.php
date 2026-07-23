<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringSocketExportStream;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * socket_export_stream() — Socket to stream resource (php-src ext/sockets/sockets.c; #6349).
 *
 * Inverse of socket_import_stream(); returns the VmFs handle for imported Sockets,
 * or wraps a socket_create() fd as a stream (#6349, #22542).
 */
final class socket_export_stream extends Internal
{
    public function __construct()
    {
        parent::__construct('socket_export_stream');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'socket_export_stream() expects exactly 1 argument, '.$argc.' given'
            );
        }

        $object = VmSocketArg::requireSocketObject($frame->calledArgs[0], 'socket_export_stream', 1);
        $exported = VmSocket::exportStream($object, $frame->vmContext);
        if (false === $exported) {
            VmSockets::triggerWarning($frame, 'socket_export_stream(): Unable to export socket');
            BuiltinExecute::writeReturn(
                $frame,
                static fn (Variable $ret) => $ret->bool(false)
            );

            return;
        }

        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->copyFrom($exported)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            return JitSocketExportStream::emitArgumentCountError($context, $argc);
        }

        return JitSocketExportStream::invoke($context, $args[0]);
    }
}
