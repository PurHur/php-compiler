<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/**
 * socket_bind() — bind(2) for AF_INET / AF_UNIX (php-src ext/sockets/sockets.c; #6176, #20268).
 *
 * @see https://github.com/php/php-src/blob/master/ext/sockets/sockets.c PHP_FUNCTION(socket_bind)
 */
final class socket_bind extends Internal
{
    public function __construct()
    {
        parent::__construct('socket_bind');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                'socket_bind() expects at least 2 arguments, '.$argc.' given'
            );
        }

        $object = VmSocketArg::requireSocketObject($frame->calledArgs[0], 'socket_bind', 1);
        // VmString::coerceTypedStringBuiltinArg uses 0-based argIndex (+1 in message).
        $addr = VmString::coerceTypedStringBuiltinArg($frame->calledArgs[1], 'socket_bind', 1, 'address');
        $port = 0;
        if ($argc >= 3) {
            $port = VmSocketArg::requireIntArg($frame, 2, 'socket_bind', 3, 'port');
        }
        $ok = VmSockets::bind($object, $addr, $port, $frame);
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->bool($ok)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('socket_bind() JIT lowering not implemented (#6176)');
    }
}
