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
 * array_pad() for packed list arrays (subset of PHP; LLVM via ArrayBuiltinHelper).
 */
final class array_pad extends Internal
{
    public function __construct()
    {
        parent::__construct('array_pad');
    }

    public function execute(Frame $frame): void
    {
        if (3 !== \count($frame->calledArgs)) {
            throw new \LogicException('array_pad() requires exactly three arguments');
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
        $length = $frame->calledArgs[1]->resolveIndirect();
        $value = $frame->calledArgs[2]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('array_pad() argument #1 must be an array in this compiler build');
        }
        if (Variable::TYPE_INTEGER !== $length->type) {
            throw new \LogicException('array_pad() argument #2 must be an integer in this compiler build');
        }
        $frame->returnVar->array(
            VmArray::pad($array->toArray(), $length->toInt(), $value)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (3 !== \count($args)) {
            throw new \LogicException('array_pad() requires exactly three arguments');
        }
        if (JITVariable::TYPE_HASHTABLE !== $args[0]->type
            && !($args[0]->type & JITVariable::IS_NATIVE_ARRAY)) {
            throw new \LogicException('array_pad() argument #1 must be an array in this compiler build');
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $args[1]->type) {
            throw new \LogicException('array_pad() argument #2 must be an integer in this compiler build');
        }
        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_pad() argument #'.((int) $i + 1));
            }
        }
        $length = JitLongArg::lower($context, $args[1], 'array_pad() length');

        return ArrayBuiltinHelper::pad($context, $args[0], $length, $args[2]);
    }
}
