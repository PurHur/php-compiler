<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
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
        $name = VmString::stringBuiltinArgForFrame($frame, 0, 'class_exists', 0, 'class');
        $autoload = VmReflection::autoloadFlagFromFrame($frame);
        $exists = VmReflection::classExists($ctx, $name, $autoload);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($exists);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1 || \count($args) > 2) {
            throw new \LogicException('class_exists() requires one or two arguments in this compiler build');
        }
        $literal = JitStringArg::compileTimeLiteral($args[0]);
        if (null !== $literal) {
            return ReflectionBuiltinHelper::classExistsLiteral($context, $literal);
        }

        return JitClassExists::invoke(
            $context,
            JitStringBuiltinArg::lowerCoercible($context, $args[0], 'class_exists', 0, 'class')
        );
    }
}
