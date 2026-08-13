<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\ArrayChangeKeyCaseRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_change_key_case() — return copy with string keys case-normalized (ASCII subset; issue #78 Phase 2).
 *
 * Excess/missing argc → Zend ArgumentCountError (#30536; php-src ext/standard/array.c).
 */
final class array_change_key_case extends Internal
{
    public function __construct()
    {
        parent::__construct('array_change_key_case');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: 1..2 — #30536.
        $this->requireArgCountRange($frame, 'array_change_key_case', 1, 2);
        $argc = \count($frame->calledArgs);
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
        // Catchable ArgumentCountError under AOT try/catch (#30536 / peer #28229).
        if (!$this->requireArgCountRangeJit($context, $args, 'array_change_key_case', 1, 2)) {
            return HashTableHelper::emptyVariable($context)->value;
        }
        $argc = \count($args);
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
