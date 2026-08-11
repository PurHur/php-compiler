<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/**
 * socket_connect() — connect(2) for AF_INET (php-src ext/sockets/sockets.c; #19286, #3399).
 *
 * @see https://github.com/php/php-src/blob/master/ext/sockets/sockets.c PHP_FUNCTION(socket_connect)
 */
final class socket_connect extends Internal
{
    public function __construct()
    {
        parent::__construct('socket_connect');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                'socket_connect() expects at least 2 arguments, '.$argc.' given'
            );
        }

        $object = VmSocketArg::requireSocketObject($frame->calledArgs[0], 'socket_connect', 1);
        // Z_PARAM_STR — soft-null outside strict_types; param name $address (php-src stub; #30316).
        $addr = VmString::stringBuiltinArgForFrame($frame, 1, 'socket_connect', 1, 'address', false);
        // ?int $port = null — Z_PARAM_LONG_OR_NULL; AF_INET/AF_INET6 reject null (#30339).
        $port = null;
        if ($argc >= 3) {
            $port = VmMath::parseNullableIntBuiltinArgForFrame(
                $frame,
                2,
                'socket_connect',
                2,
                'port'
            );
        }
        $domain = VmSocket::domainForObject($object) ?? VmSockets::AF_INET;
        if (null === $port && (VmSockets::AF_INET === $domain || VmSockets::AF_INET6 === $domain)) {
            $family = VmSockets::AF_INET6 === $domain ? 'AF_INET6' : 'AF_INET';
            throw new \ValueError(
                'socket_connect(): Argument #3 ($port) cannot be null when the socket type is '.$family
            );
        }
        $ok = VmSockets::connect($object, $addr, $port ?? 0, $frame);
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->bool($ok)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('socket_connect() JIT lowering not implemented (#19286)');
    }
}
