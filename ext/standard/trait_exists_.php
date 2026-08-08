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
        // php-src Zend/zend_builtin_functions.stub.php — ArgumentCountError (#28475).
        $this->requireArgCountRange($frame, 'trait_exists', 1, 2);
        $ctx = VmReflection::requireContext($frame);
        // Z_PARAM_STR — soft-null DEP+coerce on 8.4 (#21281, zend_builtin_functions.c).
        $name = VmString::trimFamilyStringArgForFrame($frame, 0, 'trait_exists', 0, 'trait');
        $autoload = VmReflection::autoloadFlagFromFrame($frame);
        $exists = VmReflection::traitExists($ctx, $name, $autoload);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($exists);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT/JIT) — #28475.
        if (!$this->requireArgCountRangeJit($context, $args, 'trait_exists', 1, 2)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        $literal = JitStringArg::compileTimeLiteral($args[0]);
        if (null !== $literal) {
            return ReflectionBuiltinHelper::traitExistsLiteral($context, $literal);
        }
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            self::jitNameArg($context, $args[0]);
            $i1 = $context->getTypeFromString('int1');

            return $i1->constInt(0, false);
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

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'trait_exists',
            0,
            'trait'
        );
    }
}
