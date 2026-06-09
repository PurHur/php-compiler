<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * HashContext::__debugInfo() — expose algorithm name only (php-src ext/hash/hash.c; #7084).
 */
final class HashContextDebugInfo extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__debugInfo');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('HashContext::__debugInfo() expects a HashContext receiver');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('HashContext::__debugInfo() expects a HashContext receiver');
        }
        $object = $receiver->toObject();
        $algoName = VmHashContext::debugInfoAlgoName($object);
        $ht = new HashTable();
        $algoVar = new Variable();
        $algoVar->string($algoName);
        $ht->addNew('algo', $algoVar);
        $frame->returnVar->array($ht);
    }
}
