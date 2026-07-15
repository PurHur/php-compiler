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

/** trait_exists() — whether a user trait is registered (issue #1371, #19223). */
final class trait_exists_ extends Internal
{
    public function __construct()
    {
        parent::__construct('trait_exists');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1 || \count($frame->calledArgs) > 2) {
            throw new \LogicException('trait_exists() requires one or two arguments in this compiler build');
        }
        $ctx = VmReflection::requireContext($frame);
        // Z_PARAM_STR — null TypeError on 8.4 forward profile (#19223, zend_builtin_functions.c).
        $name = VmString::zparamStrBuiltinArgForFrame($frame, 0, 'trait_exists', 0, 'trait');
        $autoload = VmReflection::autoloadFlagFromFrame($frame);
        $exists = VmReflection::traitExists($ctx, $name, $autoload);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($exists);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1 || \count($args) > 2) {
            throw new \LogicException('trait_exists() requires one or two arguments in this compiler build');
        }
        $literal = JitStringArg::compileTimeLiteral($args[0]);
        if (null !== $literal) {
            return ReflectionBuiltinHelper::traitExistsLiteral($context, $literal);
        }

        return JitTraitExists::invokeLowered(
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
                'trait_exists',
                0,
                'trait'
            );
        }

        return JitStringBuiltinArg::lowerZparamStr(
            $context,
            $arg,
            'trait_exists',
            0,
            'trait'
        );
    }
}
