<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\ArrayCountValuesRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * array_count_values() for string or integer values (subset of PHP; issue #2356, #27213).
 *
 * VM: {@see VmArray::countValues()}; JIT/AOT: {@see ArrayCountValuesRuntime::countValues()}
 * via call-site {@see \PHPCompiler\JIT\ArrayCountValuesLlvm} (not NestedJIT helper).
 *
 * Excess/missing argc → Zend ArgumentCountError (#30536; php-src ext/standard/array.c).
 */
final class array_count_values extends Internal
{
    public function __construct()
    {
        parent::__construct('array_count_values');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: exactly 1 — #30536.
        $this->requireExactArgCount($frame, 'array_count_values', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $ht = VmArray::requireArrayParam($frame->calledArgs[0], 'array_count_values', 1, 'array');
        $frame->returnVar->array(VmArray::countValues($ht, $frame));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30536 / peer #28229).
        if (!$this->requireExactJitArgCount($context, $args, 'array_count_values', 1)) {
            return HashTableHelper::emptyVariable($context)->value;
        }
        TypeErrorRaise::ensureLinked($context);
        JitArrayElem::requireArrayParam($context, $args[0], 'array_count_values', 1, 'array');
        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_count_values() argument #'.((int) $i + 1));
            }
        }

        return ArrayCountValuesRuntime::countValues($context, $args[0]);
    }
}
