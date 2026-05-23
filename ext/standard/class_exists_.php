<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** class_exists() — whether a user class is registered (issue #1214). */
final class class_exists_ extends Internal
{
    public function __construct()
    {
        parent::__construct('class_exists');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1 || \count($frame->calledArgs) > 2) {
            throw new \LogicException('class_exists() requires one or two arguments in this compiler build');
        }
        $ctx = VmReflection::requireContext($frame);
        $name = VmReflection::stringArg($frame->calledArgs[0], 'class_exists() class name');
        $exists = VmReflection::classExists($ctx, $name);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($exists);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1 || \count($args) > 2) {
            throw new \LogicException('class_exists() requires one or two arguments in this compiler build');
        }
        if (JITVariable::TYPE_STRING === $args[0]->type || JITVariable::TYPE_VALUE === $args[0]->type) {
            $this->jitString($context, $args[0], 'class_exists() class name');
        }
        $name = ReflectionBuiltinHelper::requireCompileTimeClassName(
            $context,
            $args[0],
            'class_exists() class name'
        );

        return ReflectionBuiltinHelper::classExistsLiteral($context, $name);
    }
}
