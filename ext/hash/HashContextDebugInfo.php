<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * HashContext::__debugInfo() — expose algorithm name only (php-src ext/hash/hash.c; #7084, #22563).
 *
 * Registered only when {@see \PHPCompiler\CompilerVersion::supportsHashContextDebugInfo()} —
 * PHP 8.4+ stub; absent on Zend 8.2/8.3.
 */
final class HashContextDebugInfo extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__debugInfo');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('HashContext::__debugInfo() expects a HashContext receiver');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('HashContext::__debugInfo() expects a HashContext receiver');
        }
        $object = $receiver->toObject();
        $algoName = VmHashContext::debugInfoAlgoName($object);
        if (null === $frame->returnVar) {
            return;
        }
        $ht = new HashTable();
        $algoVar = new Variable();
        $algoVar->string($algoName);
        $ht->addNew('algo', $algoVar);
        $frame->returnVar->array($ht);
    }
}
