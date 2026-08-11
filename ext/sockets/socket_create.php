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
 * socket_create() — BSD socket(2) as Socket (php-src ext/sockets/sockets.c; #19286, #3399).
 *
 * @see https://github.com/php/php-src/blob/master/ext/sockets/sockets.c PHP_FUNCTION(socket_create)
 */
final class socket_create extends Internal
{
    public function __construct()
    {
        parent::__construct('socket_create');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(
                'socket_create() expects exactly 3 arguments, '.$argc.' given'
            );
        }

        $domain = VmSocketArg::requireIntArg($frame, 0, 'socket_create', 1, 'domain');
        $type = VmSocketArg::requireIntArg($frame, 1, 'socket_create', 2, 'type');
        $protocol = VmSocketArg::requireIntArg($frame, 2, 'socket_create', 3, 'protocol');
        $object = VmSockets::create($domain, $type, $protocol, $frame->vmContext);
        if (false === $object) {
            BuiltinExecute::writeReturn(
                $frame,
                static fn (Variable $ret) => $ret->bool(false)
            );

            return;
        }

        BuiltinExecute::writeReturn(
            $frame,
            static function (Variable $ret) use ($object): void {
                $ret->object($object);
            }
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (3 !== $argc) {
            return JitSocketCreate::emitArgumentCountError($context, $argc);
        }

        return JitSocketCreate::invoke($context, $args[0], $args[1], $args[2]);
    }
}
