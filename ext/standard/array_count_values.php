<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_count_values() for string or integer values (subset of PHP; issue #2356).
 *
 * VM: {@see VmArray::countValues()}; JIT/AOT: {@see ArrayBuiltinHelper::arrayCountValues()}.
 */
final class array_count_values extends Internal
{
    public function __construct()
    {
        parent::__construct('array_count_values');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('array_count_values() requires exactly one argument');
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('array_count_values() argument must be an array in this compiler build');
        }
        $frame->returnVar->array(VmArray::countValues($array->toArray()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('array_count_values() requires exactly one argument');
        }
        if (JITVariable::TYPE_HASHTABLE !== $args[0]->type
            && !($args[0]->type & JITVariable::IS_NATIVE_ARRAY)) {
            throw new \LogicException('array_count_values() argument must be an array in this compiler build');
        }
        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_count_values() argument #'.((int) $i + 1));
            }
        }

        return ArrayBuiltinHelper::arrayCountValues($context, $args[0]);
    }
}
