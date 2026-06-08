<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ClosureSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/** Closure::__debugInfo() — var_dump metadata (issue #7069, Zend zend_closure_get_debug_info). */
final class ClosureDebugInfo extends VmClassMethod
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
            throw new \LogicException('Closure::__debugInfo() expects a closure receiver');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('Closure::__debugInfo() expects a closure receiver');
        }
        $state = ClosureSupport::requireClosureState($receiver->toObject(), 'Closure::__debugInfo()');
        $ht = new HashTable();
        foreach ($state->debugInfoEntries() as $name => $value) {
            $ht->addNew($name, $value);
        }
        $frame->returnVar->array($ht);
    }
}
