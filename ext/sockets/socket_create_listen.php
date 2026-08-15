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
 * socket_create_listen() — AF_INET listener on INADDR_ANY (php-src ext/sockets/sockets.c; #6212).
 *
 * @see https://github.com/php/php-src/blob/master/ext/sockets/sockets.c PHP_FUNCTION(socket_create_listen)
 */
final class socket_create_listen extends Internal
{
    /** php-src 8.2 stub default (8.4+ uses SOMAXCONN). */
    private const DEFAULT_BACKLOG = 128;

    public function __construct()
    {
        parent::__construct('socket_create_listen');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError(
                'socket_create_listen() expects at least 1 argument, '.$argc.' given'
            );
        }
        if ($argc > 2) {
            throw new \ArgumentCountError(
                'socket_create_listen() expects at most 2 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('socket_create_listen() requires VM context');
        }

        $port = VmSocketArg::requireIntArg($frame, 0, 'socket_create_listen', 1, 'port');
        $backlog = self::DEFAULT_BACKLOG;
        if ($argc >= 2) {
            $backlog = VmSocketArg::requireIntArg($frame, 1, 'socket_create_listen', 2, 'backlog');
        }
        $object = VmSockets::createListen($port, $backlog, $frame->vmContext, $frame);
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
        return JitSocketCreateListen::invoke($context, ...$args);
    }
}
