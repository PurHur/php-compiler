<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * socket_cmsg_space() — CMSG_SPACE for ancillary buffers (php-src sendrecvmsg.c; #6333).
 */
final class socket_cmsg_space extends Internal
{
    public function __construct()
    {
        parent::__construct('socket_cmsg_space');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                $argc < 2
                    ? 'socket_cmsg_space() expects at least 2 arguments, '.$argc.' given'
                    : 'socket_cmsg_space() expects at most 3 arguments, '.$argc.' given'
            );
        }
        $level = VmSocketArg::requireIntArg($frame, 0, 'socket_cmsg_space', 1, 'level');
        $type = VmSocketArg::requireIntArg($frame, 1, 'socket_cmsg_space', 2, 'type');
        $num = 0;
        if ($argc >= 3) {
            $num = VmSocketArg::requireIntArg($frame, 2, 'socket_cmsg_space', 3, 'num');
        }
        $space = VmSocketMsg::cmsgSpace($level, $type, $num);
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->int((int) $space)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('socket_cmsg_space() JIT lowering not implemented (#6333)');
    }
}
