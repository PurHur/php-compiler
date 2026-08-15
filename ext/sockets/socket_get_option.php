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
class socket_get_option extends Internal
{
    public function __construct()
    {
        parent::__construct('socket_get_option');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        $fn = $this->getName();
        if (3 !== $argc) {
            throw new \ArgumentCountError(
                $fn.'() expects exactly 3 arguments, '.$argc.' given'
            );
        }

        $object = VmSocketArg::requireSocketObject($frame->calledArgs[0], $fn, 1);
        $level = VmSocketArg::requireIntArg($frame, 1, $fn, 2, 'level');
        $option = VmSocketArg::requireIntArg($frame, 2, $fn, 3, 'option');
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
        return JitSocketGetOption::invoke($context, ...$args);
    }
}
