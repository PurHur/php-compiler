<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_diff_assoc() — diff with strict key+value equality (php-src ext/standard/array.c, #3129).
 */
final class array_diff_assoc extends Internal
{
    use VmArrayAssocSetOps;

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError('array_diff_assoc() expects at least 1 argument, 0 given');
        }
        $first = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $first->type) {
            throw new \LogicException('array_diff_assoc() first argument must be an array in this compiler build');
        }
        self::guardSetOpOperands($frame, $frame->calledArgs, 'array_diff_assoc');
        if (1 === $argc) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->array($first->toArray()->replaceCopy());
            }

            return;
        }
        if (null === $frame->returnVar) {
            return;
        }
        $others = self::collectOtherHashTables($frame->calledArgs, 'array_diff_assoc');
        $out = new HashTable();
        foreach ($first->toArray()->iterateKeyed(true) as [$key, $value]) {
            if (self::pairInAnyOther($key, $value, $others)) {
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
            throw new \ArgumentCountError('array_diff_assoc() expects at least 1 argument, 0 given');
        }

        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_diff_assoc() argument #'.((int) $i + 1));
            }
        }

        return ArrayBuiltinHelper::arrayDiffAssoc($context, ...$args);
    }
}
