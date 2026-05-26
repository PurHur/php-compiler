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
 * shuffle() — randomize packed list order in place (subset of PHP; issue #2310).
 *
 * VM: packed lists without holes; Fisher–Yates via CSPRNG.
 * JIT/AOT: {@see ArrayBuiltinHelper::shufflePacked()}.
 */
final class shuffle_ extends Internal
{
    public function __construct()
    {
        parent::__construct('shuffle');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('shuffle() requires exactly one argument');
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('shuffle() argument must be an array in this compiler build');
        }
        VmArray::shufflePacked($array->toArray());
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('shuffle() requires exactly one argument');
        }
        ArrayBuiltinHelper::shufflePacked($context, $args[0]);

        return $context->getTypeFromString('int1')->constInt(1, false);
    }
}
