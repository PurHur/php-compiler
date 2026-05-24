<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
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
        $subject = $frame->calledArgs[0]->resolveIndirect();
        if (\PHPCompiler\VM\Variable::TYPE_OBJECT === $subject->type) {
            $exists = VmReflection::propertyExistsOnObject($subject->toObject(), $property);
        } elseif (\PHPCompiler\VM\Variable::TYPE_STRING === $subject->type) {
            $class = VmReflection::resolveClassEntry($ctx, $subject->toString());
            $exists = null !== $class && VmReflection::propertyExistsOnClass($ctx, $class, $property);
        } else {
            throw new \LogicException('property_exists() subject must be an object or class name string in this compiler build');
        }
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
