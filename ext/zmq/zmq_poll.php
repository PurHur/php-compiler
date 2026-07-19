<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zmq;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmMath;

/**
 * zmq_poll() — poll sockets for readiness (pecl-networking-zmq; #6443).
 *
 * Phase-0: accepts an array of [socket, events] pairs; returns ready subset.
 */
final class zmq_poll extends ZmqFunction
{
    public function __construct()
    {
        parent::__construct('zmq_poll');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'zmq_poll() expects 1-2 arguments, '.$argc.' given'
            );
        }
        $listVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $listVar->type) {
            throw new \TypeError(
                'zmq_poll(): Argument #1 ($poll_items) must be of type array, '.
                EnumCaseSupport::typeNameForVariable($listVar).' given'
            );
        }
        $timeout = -1;
        if (2 === $argc) {
            $timeout = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'zmq_poll', 2, 'timeout');
        }
        $items = [];
        foreach ($listVar->toArray()->iterateKeyed(true) as [, $entry]) {
            if (!$entry instanceof Variable || Variable::TYPE_ARRAY !== $entry->type) {
                continue;
            }
            $inner = $entry->toArray();
            $sockVar = $inner->findIndex(0);
            $evVar = $inner->findIndex(1);
            if (null === $sockVar || null === $evVar) {
                continue;
            }
            $sockVar = $sockVar->resolveIndirect();
            if (Variable::TYPE_OBJECT !== $sockVar->type) {
                continue;
            }
            $items[] = [
                $sockVar->toObject(),
                (int) $evVar->resolveIndirect()->toInt(),
            ];
        }
        $ready = VmZmq::poll($items, $timeout);
        BuiltinExecute::writeReturn(
            $frame,
            static function (Variable $ret) use ($ready): void {
                $ht = new HashTable();
                $i = 0;
                foreach ($ready as $pair) {
                    $row = new HashTable();
                    $sockVar = new Variable();
                    $sockVar->object($pair[0]);
                    $row->addIndex(0, $sockVar);
                    $evVar = new Variable(Variable::TYPE_INTEGER);
                    $evVar->int($pair[1]);
                    $row->addIndex(1, $evVar);
                    $rowVar = new Variable();
                    $rowVar->array($row);
                    $ht->addIndex($i, $rowVar);
                    ++$i;
                }
                $ret->array($ht);
            }
        );
    }
}
