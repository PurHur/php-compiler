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
 * socket_last_error() — last errno for Socket or process (php-src ext/sockets/sockets.c; #6227).
 *
 * @see https://github.com/php/php-src/blob/master/ext/sockets/sockets.c PHP_FUNCTION(socket_last_error)
 */
final class socket_last_error extends Internal
{
    public function __construct()
    {
        parent::__construct('socket_last_error');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'socket_last_error() expects at most 1 argument, '.$argc.' given'
            );
        }
        $object = null;
        if (1 === $argc) {
            $object = VmSocketArg::requireSocketObjectOrNull($frame->calledArgs[0], 'socket_last_error', 1);
        }
        $errno = VmSockets::lastError($object);
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->int($errno)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('socket_last_error() JIT lowering not implemented (#6227)');
    }
}
