<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
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
        $class = VmReflection::resolveClassFromArg($ctx, $frame->calledArgs[0]);
        $method = VmReflection::stringArg($frame->calledArgs[1], 'method_exists() method name');
        $exists = VmReflection::methodExistsOnClass($class, $method);
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
        if (JITVariable::TYPE_OBJECT === $args[0]->type) {
            throw new \LogicException('method_exists() on object requires a string literal class name in JIT in this compiler build');
        }
        $className = ReflectionBuiltinHelper::requireCompileTimeClassName(
            $context,
            $args[0],
            'method_exists() class name'
        );
        $method = ReflectionBuiltinHelper::requireCompileTimeClassName(
            $context,
            $args[1],
            'method_exists() method name'
        );

        return ReflectionBuiltinHelper::methodExistsLiteral($context, $className, $method);
    }
}
