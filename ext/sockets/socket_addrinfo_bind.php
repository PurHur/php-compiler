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
 * socket_addrinfo_bind() — socket+bind from AddressInfo (php-src ext/sockets; #6064).
 *
 * @see https://github.com/php/php-src/blob/master/ext/sockets/sockets.c PHP_FUNCTION(socket_addrinfo_bind)
 */
final class socket_addrinfo_bind extends Internal
{
    public function __construct()
    {
        parent::__construct('socket_addrinfo_bind');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'socket_addrinfo_bind() expects exactly 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('socket_addrinfo_bind() requires VM context');
        }

        $address = socket_addrinfo_explain::requireAddressInfo($frame->calledArgs[0], 'socket_addrinfo_bind');
        $object = VmSocketAddrinfo::bind($address, $frame->vmContext, $frame);
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
        return JitSocketAddrinfoBind::invoke($context, ...$args);
    }
}
