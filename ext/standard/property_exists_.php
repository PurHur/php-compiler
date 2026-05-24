<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** property_exists() — whether a property is defined on object or class (issue #1372). */
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
        if (JITVariable::TYPE_STRING === $args[0]->type || JITVariable::TYPE_VALUE === $args[0]->type) {
            $this->jitString($context, $args[0], 'property_exists() class name');
        }
        if (JITVariable::TYPE_STRING === $args[1]->type || JITVariable::TYPE_VALUE === $args[1]->type) {
            $this->jitString($context, $args[1], 'property_exists() property name');
        }
        if (JITVariable::TYPE_OBJECT === $args[0]->type) {
            throw new \LogicException('property_exists() on object requires a string literal class name in JIT in this compiler build');
        }
        $className = ReflectionBuiltinHelper::requireCompileTimeClassName(
            $context,
            $args[0],
            'property_exists() class name'
        );
        $property = ReflectionBuiltinHelper::requireCompileTimeClassName(
            $context,
            $args[1],
            'property_exists() property name'
        );

        return ReflectionBuiltinHelper::propertyExistsLiteral($context, $className, $property);
    }
}
