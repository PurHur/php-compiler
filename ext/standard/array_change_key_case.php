<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\ArrayChangeKeyCaseRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
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
        if (null === $frame->returnVar) {
            return;
        }
        $ht = VmArray::requireArrayParam($frame->calledArgs[0], 'array_change_key_case', 1, 'array');
        $case = StdlibConstants::CASE_LOWER;
        if ($argc >= 2) {
            $case = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[1],
                'array_change_key_case',
                2,
                'case'
            );
        }
        $frame->returnVar->array(VmArray::changeKeyCase($ht, $case));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('array_change_key_case() requires one or two arguments in this compiler build');
        }
        TypeErrorRaise::ensureLinked($context);
        JitArrayElem::requireArrayParam($context, $args[0], 'array_change_key_case', 1, 'array');
        $i64 = $context->getTypeFromString('int64');
        $case = $i64->constInt(StdlibConstants::CASE_LOWER, false);
        if ($argc >= 2) {
            $case = JitIntdiv::lowerIntBuiltinArg($context, $args[1], 'array_change_key_case', 2, 'case');
        }

        return ArrayChangeKeyCaseRuntime::changeKeyCase($context, $args[0], $case);
    }
}
