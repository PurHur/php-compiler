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
 * socket_select() — multiplex Socket objects via poll(2) (php-src ext/sockets/sockets.c; #6395 / #31355).
 *
 * Thin AOT/JIT via {@see JitSocketSelect} + SocketCreateJitHelper select slots (#31355).
 *
 * @see https://github.com/php/php-src/blob/master/ext/sockets/sockets.c PHP_FUNCTION(socket_select)
 */
final class socket_select extends Internal
{
    public function __construct()
    {
        parent::__construct('socket_select');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 4) {
            throw new \ArgumentCountError(
                'socket_select() expects at least 4 arguments, '.$argc.' given'
            );
        }
        if ($argc > 5) {
            throw new \ArgumentCountError(
                'socket_select() expects at most 5 arguments, '.$argc.' given'
            );
        }

        $readSlots = self::parseSocketArrayArg($frame->calledArgs[0], 1, 'read');
        $writeSlots = self::parseSocketArrayArg($frame->calledArgs[1], 2, 'write');
        $exceptSlots = self::parseSocketArrayArg($frame->calledArgs[2], 3, 'except');

        if (null === $readSlots && null === $writeSlots && null === $exceptSlots) {
            throw new \ValueError('socket_select(): At least one array argument must be passed');
        }

        $seconds = VmSocketArg::requireIntArg($frame, 3, 'socket_select', 4, 'seconds');
        $microseconds = 0;
        if ($argc >= 5) {
            $microseconds = VmSocketArg::requireIntArg($frame, 4, 'socket_select', 5, 'microseconds');
        }

        $result = VmSockets::select(
            $readSlots,
            $writeSlots,
            $exceptSlots,
            $seconds,
            $microseconds
        );
        if (false === $result) {
            VmSockets::triggerWarning($frame, 'socket_select(): unable to select');
            BuiltinExecute::writeReturn(
                $frame,
                static fn (Variable $ret) => $ret->bool(false)
            );

            return;
        }

        if (null !== $readSlots) {
            self::writeBackSocketArray($frame->calledArgs[0], $result['read']);
        }
        if (null !== $writeSlots) {
            self::writeBackSocketArray($frame->calledArgs[1], $result['write']);
        }
        if (null !== $exceptSlots) {
            self::writeBackSocketArray($frame->calledArgs[2], $result['except']);
        }

        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->int($result['count'])
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitSocketSelect::invoke($context, ...$args);
    }

    /**
     * @return list<array{key: int|string, object: \PHPCompiler\VM\ObjectEntry, fd: int}>|null
     */
    private static function parseSocketArrayArg(Variable $arg, int $argNum, string $paramName): ?array
    {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_NULL === $arg->type) {
            return null;
        }
        if (Variable::TYPE_ARRAY !== $arg->type) {
            throw new \TypeError(\sprintf(
                'socket_select(): Argument #%d ($%s) must be of type ?array, %s given',
                $argNum,
                $paramName,
                \PHPCompiler\ext\standard\VmStreamArg::debugTypeName($arg)
            ));
        }

        $slots = [];
        foreach ($arg->toArray()->iterateKeyed(true) as [$keyVar, $value]) {
            $value = $value->resolveIndirect();
            try {
                $object = VmSocketArg::requireSocketObject($value, 'socket_select', $argNum);
            } catch (\TypeError) {
                $given = \PHPCompiler\ext\standard\VmStreamArg::debugTypeName($value);
                throw new \TypeError(\sprintf(
                    'socket_select(): Argument #%d ($%s) must only have elements of type Socket, %s given',
                    $argNum,
                    $paramName,
                    $given
                ));
            }
            $fd = VmSocket::fdForObject($object);
            if (null === $fd) {
                throw new \TypeError(
                    'socket_select(): supplied resource is not a valid Socket resource'
                );
            }
            $keyVar = $keyVar->resolveIndirect();
            $key = Variable::TYPE_INTEGER === $keyVar->type
                ? $keyVar->toInt()
                : $keyVar->toString();
            $slots[] = [
                'key' => $key,
                'object' => $object,
                'fd' => $fd,
            ];
        }

        return $slots;
    }

    /**
     * @param list<array{key: int|string, object: \PHPCompiler\VM\ObjectEntry, fd: int}> $ready
     */
    private static function writeBackSocketArray(Variable $targetVar, array $ready): void
    {
        $targetVar = $targetVar->resolveIndirect();
        $ht = new HashTable();
        foreach ($ready as $slot) {
            $cell = new Variable();
            $cell->object($slot['object']);
            $key = $slot['key'];
            if (\is_int($key)) {
                $ht->addIndex($key, $cell);
            } else {
                $ht->add((string) $key, $cell);
            }
        }
        $replacement = new Variable();
        $replacement->array($ht);
        $targetVar->copyFrom($replacement);
    }
}
