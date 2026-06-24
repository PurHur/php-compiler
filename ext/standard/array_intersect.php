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
 * array_intersect() for arrays of scalar values (loose compare; subset of PHP; issue #1207).
 */
final class array_intersect extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError('array_intersect() expects at least 1 argument, 0 given');
        }
        $first = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $first->type) {
            throw new \LogicException('array_intersect() first argument must be an array in this compiler build');
        }
        $firstHt = $first->toArray();
        $operandTables = [$firstHt];
        if (1 === $argc) {
            VmArray::rejectEnumCaseSetOpOperands($frame, $firstHt);
            if (null !== $frame->returnVar) {
                $frame->returnVar->array($firstHt->replaceCopy());
            }

            return;
        }
        $others = [];
        for ($i = 1, $n = $argc; $i < $n; ++$i) {
            $arg = $frame->calledArgs[$i]->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $arg->type) {
                throw new \LogicException('array_intersect() arguments must be arrays in this compiler build');
            }
            $others[] = $arg->toArray();
            $operandTables[] = $others[\count($others) - 1];
        }
        VmArray::rejectEnumCaseSetOpOperands($frame, ...$operandTables);
        if (null === $frame->returnVar) {
            return;
        }
        $out = new HashTable();
        foreach ($firstHt->iterateKeyed(true) as [$key, $value]) {
            if (!self::valueInAllArrays($value, $others)) {
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

    /**
     * @param list<HashTable> $arrays
     */
    private static function valueInAllArrays(Variable $needle, array $arrays): bool
    {
        $needle = $needle->resolveIndirect();
        foreach ($arrays as $haystack) {
            $found = false;
            foreach ($haystack->iterate(true) as $value) {
                $stored = $value->resolveIndirect();
                if (in_array::looseEquals($needle, $stored)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }

        return true;
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1) {
            throw new \ArgumentCountError('array_intersect() expects at least 1 argument, 0 given');
        }

        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_intersect() argument #'.((int) $i + 1));
            }
        }

        return ArrayBuiltinHelper::arrayIntersect($context, ...$args);
    }
}
