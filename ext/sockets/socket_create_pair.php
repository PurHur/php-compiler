<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * socket_create_pair() — socketpair(2) as Socket objects (php-src ext/sockets/sockets.c; #6563).
 *
 * @see https://github.com/php/php-src/blob/master/ext/sockets/sockets.c PHP_FUNCTION(socket_create_pair)
 */
final class socket_create_pair extends Internal
{
    public function __construct()
    {
        parent::__construct('socket_create_pair');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (4 !== $argc) {
            throw new \ArgumentCountError(
                'socket_create_pair() expects exactly 4 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('socket_create_pair() requires VM context');
        }

        $domain = VmSocketArg::requireIntArg($frame->calledArgs[0], 'socket_create_pair', 1, 'domain');
        $type = VmSocketArg::requireIntArg($frame->calledArgs[1], 'socket_create_pair', 2, 'type');
        $protocol = VmSocketArg::requireIntArg($frame->calledArgs[2], 'socket_create_pair', 3, 'protocol');
        $pair = VmSockets::createPair($domain, $type, $protocol, $frame->vmContext);
        if (false === $pair) {
            BuiltinExecute::writeReturn(
                $frame,
                static fn (Variable $ret) => $ret->bool(false)
            );

            return;
        }

        $ht = new HashTable();
        $slot0 = new Variable();
        $slot0->object($pair[0]);
        $ht->addIndex(0, $slot0);
        $slot1 = new Variable();
        $slot1->object($pair[1]);
        $ht->addIndex(1, $slot1);
        $pairOut = new Variable();
        $pairOut->array($ht);
        $frame->calledArgs[3]->byRefTarget()->copyFrom($pairOut);

        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->bool(true)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (4 !== $argc) {
            return JitSocketCreatePair::emitArgumentCountError($context, $argc);
        }

        return JitSocketCreatePair::invoke($context, $args[0], $args[1], $args[2], $args[3]);
    }
}
