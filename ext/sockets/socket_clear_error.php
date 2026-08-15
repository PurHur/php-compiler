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
 * socket_clear_error() — clear last errno for Socket or process (php-src ext/sockets/sockets.c; #6227).
 *
 * @see https://github.com/php/php-src/blob/master/ext/sockets/sockets.c PHP_FUNCTION(socket_clear_error)
 */
final class socket_clear_error extends Internal
{
    public function __construct()
    {
        parent::__construct('socket_clear_error');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'socket_clear_error() expects at most 1 argument, '.$argc.' given'
            );
        }
        $object = null;
        if (1 === $argc) {
            $object = VmSocketArg::requireSocketObjectOrNull($frame->calledArgs[0], 'socket_clear_error', 1);
        }
        VmSockets::clearError($object);
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->null()
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitSocketClearError::invoke($context, ...$args);
    }
}
