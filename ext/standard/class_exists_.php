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

/** class_exists() — whether a user class is registered (issue #1214, #19223). */
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
        // Z_PARAM_STR — soft-null DEP+coerce on 8.4 (#21281, zend_builtin_functions.c).
        $name = VmString::trimFamilyStringArgForFrame($frame, 0, 'class_exists', 0, 'class');
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
        // Soft-null name → false (empty class name never exists) (#21281).
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            self::jitNameArg($context, $args[0]);
            $i1 = $context->getTypeFromString('int1');

            return $i1->constInt(0, false);
        }

        return JitClassExists::invoke(
            $context,
            self::jitNameArg($context, $args[0])
        );
    }

    private static function jitNameArg(Context $context, JITVariable $arg): Value
    {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'class_exists',
                0,
                'class'
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'class_exists',
            0,
            'class'
        );
    }
}
