<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

require_once __DIR__.'/VmArrayAssocSetOps.php';

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\ArrayIntersectAssocRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_intersect_assoc() — intersect with key match and loose value equality (php-src ext/standard/array.c, #3129).
 */
final class array_intersect_assoc extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError('array_intersect_assoc() expects at least 1 argument, 0 given');
        }
        $first = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $first->type) {
            throw new \LogicException('array_intersect_assoc() first argument must be an array in this compiler build');
        }
        VmArrayAssocSetOps::guardSetOpOperands($frame, $frame->calledArgs, 'array_intersect_assoc');
        if (1 === $argc) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->array($first->toArray()->replaceCopy());
            }

            return;
        }
        if (null === $frame->returnVar) {
            return;
        }
        $others = VmArrayAssocSetOps::collectOtherHashTables($frame->calledArgs, 'array_intersect_assoc');
        $out = new HashTable();
        foreach ($first->toArray()->iterateKeyed(true) as [$key, $value]) {
            if (!VmArrayAssocSetOps::pairInAllOthers($key, $value, $others)) {
                continue;
            }
            $stored = new Variable();
            $stored->copyFrom($value);
            if (Variable::TYPE_INTEGER === $key->type) {
                $out->addIndex($key->toInt(), $stored);
            } else {
                $out->add($key->toString(), $stored);
            }
        }
        $frame->returnVar->array($out);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1) {
            throw new \ArgumentCountError('array_intersect_assoc() expects at least 1 argument, 0 given');
        }

        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_intersect_assoc() argument #'.((int) $i + 1));
            }
        }

        return ArrayIntersectAssocRuntime::intersectAssoc($context, $args[0], ...\array_slice($args, 1));
    }
}
