<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** method_exists() — whether a class defines a method (issue #1215). */
final class method_exists_ extends Internal
{
    public function __construct()
    {
        parent::__construct('method_exists');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('method_exists() requires exactly two arguments');
        }
        $ctx = VmReflection::requireContext($frame);
        $method = VmReflection::stringArg($frame->calledArgs[1], 'method_exists() method name', 1);
        $exists = VmReflection::methodExists($ctx, $frame->calledArgs[0], $method);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($exists);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('method_exists() requires exactly two arguments');
        }
        if (JITVariable::TYPE_STRING === $args[0]->type || JITVariable::TYPE_VALUE === $args[0]->type) {
            $this->jitString($context, $args[0], 'method_exists() class name');
        }
        if (JITVariable::TYPE_STRING === $args[1]->type || JITVariable::TYPE_VALUE === $args[1]->type) {
            $this->jitString($context, $args[1], 'method_exists() method name');
        }

        return JitMethodExists::invoke($context, $args[0], $args[1]);
    }
}
