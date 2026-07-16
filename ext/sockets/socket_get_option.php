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
 * socket_get_option() — getsockopt(2) (php-src ext/sockets/sockets.c; #6176).
 *
 * @see https://github.com/php/php-src/blob/master/ext/sockets/sockets.c PHP_FUNCTION(socket_get_option)
 */
final class socket_get_option extends Internal
{
    public function __construct()
    {
        parent::__construct('socket_get_option');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(
                'socket_get_option() expects exactly 3 arguments, '.$argc.' given'
            );
        }

        $object = VmSocketArg::requireSocketObject($frame->calledArgs[0], 'socket_get_option', 1);
        $level = VmSocketArg::requireIntArg($frame->calledArgs[1], 'socket_get_option', 2, 'level');
        $option = VmSocketArg::requireIntArg($frame->calledArgs[2], 'socket_get_option', 3, 'option');
        $value = VmSockets::getOption($object, $level, $option, $frame);
        if (false === $value) {
            BuiltinExecute::writeReturn(
                $frame,
                static fn (Variable $ret) => $ret->bool(false)
            );

            return;
        }
        if (\is_array($value)) {
            BuiltinExecute::writeReturn(
                $frame,
                static function (Variable $ret) use ($value): void {
                    $ht = new HashTable();
                    foreach ($value as $k => $v) {
                        $slot = new Variable();
                        $slot->int((int) $v);
                        $ht->add((string) $k, $slot);
                    }
                    $ret->array($ht);
                }
            );

            return;
        }

        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->int($value)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('socket_get_option() JIT lowering not implemented (#6176)');
    }
}
