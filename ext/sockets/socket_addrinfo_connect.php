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
 * socket_addrinfo_connect() — socket+connect from AddressInfo (php-src ext/sockets; #6064).
 *
 * @see https://github.com/php/php-src/blob/master/ext/sockets/sockets.c PHP_FUNCTION(socket_addrinfo_connect)
 */
final class socket_addrinfo_connect extends Internal
{
    public function __construct()
    {
        parent::__construct('socket_addrinfo_connect');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'socket_addrinfo_connect() expects exactly 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('socket_addrinfo_connect() requires VM context');
        }

        $address = socket_addrinfo_explain::requireAddressInfo($frame->calledArgs[0], 'socket_addrinfo_connect');
        $object = VmSocketAddrinfo::connect($address, $frame->vmContext, $frame);
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
        return JitSocketAddrinfoConnect::invoke($context, ...$args);
    }
}
