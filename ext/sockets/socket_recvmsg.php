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
use PHPCompiler\ext\standard\VmStreamArg;
use PHPLLVM\Value;

/**
 * socket_recvmsg() — recvmsg(2) (php-src ext/sockets/sendrecvmsg.c; #6333).
 */
final class socket_recvmsg extends Internal
{
    public function __construct()
    {
        parent::__construct('socket_recvmsg');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                $argc < 2
                    ? 'socket_recvmsg() expects at least 2 arguments, '.$argc.' given'
                    : 'socket_recvmsg() expects at most 3 arguments, '.$argc.' given'
            );
        }
        $object = VmSocketArg::requireSocketObject($frame->calledArgs[0], 'socket_recvmsg', 1);
        $msgArg = $frame->calledArgs[1];
        $msgVar = $msgArg->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $msgVar->type) {
            throw new \TypeError(\sprintf(
                'socket_recvmsg(): Argument #2 ($message) must be of type array, %s given',
                VmStreamArg::debugTypeName($msgVar)
            ));
        }
        $flags = 0;
        if ($argc >= 3) {
            $flags = VmSocketArg::requireIntArg($frame->calledArgs[2], 'socket_recvmsg', 3, 'flags');
        }
        $parsed = VmSocketMsg::parseRecvMessage($msgVar->toArray(), $frame);
        if (null === $parsed) {
            BuiltinExecute::writeReturn(
                $frame,
                static fn (Variable $ret) => $ret->bool(false)
            );

            return;
        }
        $got = VmSocketMsg::recvmsg($object, $parsed, $flags, $frame);
        if (false === $got) {
            BuiltinExecute::writeReturn(
                $frame,
                static fn (Variable $ret) => $ret->bool(false)
            );

            return;
        }

        $out = self::messageToVariable($got['message']);
        $frame->calledArgs[1]->byRefTarget()->copyFrom($out);

        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->int($got['bytes'])
        );
    }

    /**
     * @param array{name: null, control: array, iov: list<string>, flags: int} $message
     */
    private static function messageToVariable(array $message): Variable
    {
        $ht = new HashTable();

        $name = new Variable();
        $name->null();
        $ht->add('name', $name);

        $controlHt = new HashTable();
        $control = new Variable();
        $control->array($controlHt);
        $ht->add('control', $control);

        $iovHt = new HashTable();
        foreach ($message['iov'] as $i => $chunk) {
            $slot = new Variable();
            $slot->string($chunk);
            $iovHt->addIndex($i, $slot);
        }
        $iov = new Variable();
        $iov->array($iovHt);
        $ht->add('iov', $iov);

        $flags = new Variable();
        $flags->int($message['flags']);
        $ht->add('flags', $flags);

        $out = new Variable();
        $out->array($ht);

        return $out;
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('socket_recvmsg() JIT lowering not implemented (#6333)');
    }
}
