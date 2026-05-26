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
 * array_fill_keys() for keys list and scalar fill value (subset of PHP; JIT via ArrayBuiltinHelper).
 */
final class array_fill_keys extends Internal
{
    public function __construct()
    {
        parent::__construct('array_fill_keys');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('array_fill_keys() requires exactly two arguments');
        }
        $keysArg = $frame->calledArgs[0]->resolveIndirect();
        $value = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_ARRAY !== $keysArg->type) {
            throw new \LogicException('array_fill_keys() argument #1 must be an array in this compiler build');
        }
        $frame->returnVar->array(VmArray::fillKeys($keysArg->toArray(), $value));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('array_fill_keys() requires exactly two arguments');
        }
        if (JITVariable::TYPE_HASHTABLE !== $args[0]->type
            && !($args[0]->type & JITVariable::IS_NATIVE_ARRAY)) {
            throw new \LogicException('array_fill_keys() argument #1 must be an array in this compiler build');
        }
        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_fill_keys() argument #'.((int) $i + 1));
            }
        }

        return ArrayBuiltinHelper::fillKeys($context, $args[0], $args[1]);
    }
}
