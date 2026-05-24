<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** property_exists() — whether a class or object has a declared property (issue #1372). */
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
        $arg0 = $frame->calledArgs[0]->resolveIndirect();
        $exists = false;
        if (Variable::TYPE_OBJECT === $arg0->type) {
            $exists = $arg0->toObject()->propertyNameExists($property);
        } elseif (Variable::TYPE_STRING === $arg0->type) {
            $entry = VmReflection::resolveClassEntry($ctx, $arg0->toString());
            $exists = null !== $entry && VmReflection::propertyExistsOnClass($entry, $property);
        } else {
            throw new \LogicException('property_exists() object or class name must be object or string in this compiler build');
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
            throw new \LogicException('property_exists() on object requires compile-time string literals in JIT in this compiler build');
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
