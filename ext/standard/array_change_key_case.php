<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_change_key_case() — return copy with string keys case-normalized (ASCII subset; issue #78 Phase 2).
 */
final class array_change_key_case extends Internal
{
    public function __construct()
    {
        parent::__construct('array_change_key_case');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('array_change_key_case() requires one or two arguments in this compiler build');
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('array_change_key_case() argument must be an array in this compiler build');
        }
        $case = StdlibConstants::CASE_LOWER;
        if ($argc >= 2) {
            $caseVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $caseVar->type) {
                throw new \LogicException('array_change_key_case() case must be an integer in this compiler build');
            }
            $case = $caseVar->toInt();
        }
        $frame->returnVar->array(VmArray::changeKeyCase($array->toArray(), $case));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('array_change_key_case() requires one or two arguments in this compiler build');
        }
        if (JITVariable::TYPE_HASHTABLE !== $args[0]->type
            && !($args[0]->type & JITVariable::IS_NATIVE_ARRAY)) {
            throw new \LogicException('array_change_key_case() argument must be an array in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $case = $i64->constInt(StdlibConstants::CASE_LOWER, false);
        if ($argc >= 2) {
            $case = JitLongArg::lower($context, $args[1], 'array_change_key_case() case');
        }

        return ArrayBuiltinHelper::buildChangeKeyCaseArray($context, $args[0], $case);
    }
}
