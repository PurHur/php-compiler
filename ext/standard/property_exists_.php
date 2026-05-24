<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** property_exists() — whether a class or object has a property (issue #1372). */
final class property_exists_ extends Internal
{
    public function __construct()
    {
        parent::__construct('property_exists');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('property_exists() requires exactly two arguments');
        }
        $ctx = VmReflection::requireContext($frame);
        $property = VmReflection::stringArg($frame->calledArgs[1], 'property_exists() property name');
        $exists = VmReflection::propertyExists($ctx, $frame->calledArgs[0], $property);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($exists);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('property_exists() requires exactly two arguments');
        }
        if (JITVariable::TYPE_STRING !== $args[1]->type && JITVariable::TYPE_VALUE !== $args[1]->type) {
            throw new \LogicException('property_exists() property name must be a string in this compiler build');
        }

        return JitPropertyExists::invoke($context, $args[0], $args[1]);
    }
}
