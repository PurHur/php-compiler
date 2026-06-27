<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\ArrayIntersectRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
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
        $firstHt = VmArray::requireArrayArgNum(
            $frame->calledArgs[0]->resolveIndirect(),
            'array_intersect',
            1
        );
        $operandTables = [$firstHt];
        if (1 === $argc) {
            VmArray::rejectEnumCaseSetOpOperands($frame, $firstHt);
            if (null !== $frame->returnVar) {
                $frame->returnVar->array(VmArray::intersectSingleArgumentCopy($firstHt));
            }

            return;
        }
        $others = [];
        for ($i = 1, $n = $argc; $i < $n; ++$i) {
            $others[] = VmArray::requireArrayArgNum(
                $frame->calledArgs[$i]->resolveIndirect(),
                'array_intersect',
                $i + 1
            );
            $operandTables[] = $others[\count($others) - 1];
        }
        VmArray::rejectEnumCaseSetOpOperands($frame, ...$operandTables);
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmArray::intersectSingleArgumentCopy($firstHt);
        foreach ($others as $other) {
            $result = VmArray::intersectTwo($result, $other);
        }
        $frame->returnVar->array($result);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1) {
            throw new \ArgumentCountError('array_intersect() expects at least 1 argument, 0 given');
        }

        TypeErrorRaise::ensureLinked($context);
        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_intersect() argument #'.((int) $i + 1));
            }
            JitArrayElem::requireArrayArgNum($context, $arg, 'array_intersect', $i + 1);
        }

        return ArrayIntersectRuntime::intersect($context, $args[0], ...\array_slice($args, 1));
    }
}
