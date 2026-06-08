<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\JIT\Builtin\Bcmath;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering helper for bcmath builtins via __compiler_bc* runtime bodies (#6100). */
final class JitBcmath
{
    public static int $compileTimeScale = 0;

    private static bool $compileTimeScaleKnown = true;

    public static function scale(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \LogicException('bcscale() accepts zero or one argument in this compiler build');
        }

        if (0 === \count($args) && self::$compileTimeScaleKnown) {
            return self::boxLong($context, $context->getTypeFromString('int64')->constInt(self::$compileTimeScale, true));
        }

        if (1 === \count($args)) {
            $scaleLit = self::compileTimeLong($args[0]);
            if (null !== $scaleLit && self::$compileTimeScaleKnown) {
                $old = self::$compileTimeScale;
                self::$compileTimeScale = $scaleLit;

                return self::boxLong($context, $context->getTypeFromString('int64')->constInt($old, true));
            }
        }

        self::$compileTimeScaleKnown = false;
        Bcmath::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $scale = 0 === \count($args) ? $i64->constInt(0, true) : self::lowerScaleArg($context, $args[0], 'bcscale', 0, 'scale');
        $hasScale = 0 === \count($args) ? $i32->constInt(-1, true) : $i32->constInt(1, true);

        return self::boxLong(
            $context,
            $context->builder->call(
                $context->lookupFunction('__compiler_bcscale'),
                $scale,
                $hasScale
            )
        );
    }

    public static function add(Context $context, JITVariable ...$args): Value
    {
        return self::stringBinaryOp($context, 'bcadd', 'add', $args, 'num1', 'num2');
    }

    public static function sub(Context $context, JITVariable ...$args): Value
    {
        return self::stringBinaryOp($context, 'bcsub', 'sub', $args, 'num1', 'num2');
    }

    public static function mul(Context $context, JITVariable ...$args): Value
    {
        return self::stringBinaryOp($context, 'bcmul', 'mul', $args, 'num1', 'num2');
    }

    public static function div(Context $context, JITVariable ...$args): Value
    {
        return self::stringBinaryOp($context, 'bcdiv', 'div', $args, 'num1', 'num2');
    }

    public static function comp(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2 || \count($args) > 3) {
            throw new \LogicException('bccomp() requires two or three arguments in this compiler build');
        }

        $leftLit = self::compileTimeString($args[0]);
        $rightLit = self::compileTimeString($args[1]);
        $scaleLit = isset($args[2]) ? self::compileTimeLong($args[2]) : null;
        $canFold = null !== $leftLit && null !== $rightLit && (!isset($args[2]) || null !== $scaleLit);
        if ($canFold && self::$compileTimeScaleKnown) {
            $scale = null !== $scaleLit ? $scaleLit : self::$compileTimeScale;

            return self::boxLong(
                $context,
                $context->getTypeFromString('int64')->constInt(VmBcmath::comp($leftLit, $rightLit, $scale), true)
            );
        }

        Bcmath::ensureLinked($context);
        $left = JitStringBuiltinArg::lower($context, $args[0], 'bccomp', 0, 'num1');
        $right = JitStringBuiltinArg::lower($context, $args[1], 'bccomp', 1, 'num2');
        [$scale, $hasScale] = self::scaleAndFlag($context, $args, 2, 'bccomp');
        $result = $context->builder->call(
            $context->lookupFunction('__compiler_bccomp'),
            $left,
            $right,
            $scale,
            $hasScale
        );

        return self::boxLong($context, $result);
    }

    public static function powmod(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 3 || \count($args) > 4) {
            throw new \LogicException('bcpowmod() requires three or four arguments in this compiler build');
        }

        $baseLit = self::compileTimeString($args[0]);
        $expLit = self::compileTimeString($args[1]);
        $modLit = self::compileTimeString($args[2]);
        $scaleLit = isset($args[3]) ? self::compileTimeLong($args[3]) : null;
        $canFold = null !== $baseLit && null !== $expLit && null !== $modLit && (!isset($args[3]) || null !== $scaleLit);
        if ($canFold && self::$compileTimeScaleKnown) {
            $scale = null !== $scaleLit ? $scaleLit : self::$compileTimeScale;

            return $context->builder->load(
                $context->constantStringFromString(VmBcmath::powmod($baseLit, $expLit, $modLit, $scale))
            );
        }

        Bcmath::ensureLinked($context);
        $base = JitStringBuiltinArg::lower($context, $args[0], 'bcpowmod', 0, 'num');
        $exp = JitStringBuiltinArg::lower($context, $args[1], 'bcpowmod', 1, 'exponent');
        $mod = JitStringBuiltinArg::lower($context, $args[2], 'bcpowmod', 2, 'modulus');
        [$scale, $hasScale] = self::scaleAndFlag($context, $args, 3, 'bcpowmod');

        return $context->builder->call(
            $context->lookupFunction('__compiler_bcpowmod'),
            $base,
            $exp,
            $mod,
            $scale,
            $hasScale
        );
    }

    /** @param array<int, JITVariable> $args */
    private static function stringBinaryOp(
        Context $context,
        string $function,
        string $vmMethod,
        array $args,
        string $leftName,
        string $rightName
    ): Value {
        if (\count($args) < 2 || \count($args) > 3) {
            throw new \LogicException($function.'() requires two or three arguments in this compiler build');
        }
        $leftLit = self::compileTimeString($args[0]);
        $rightLit = self::compileTimeString($args[1]);
        $scaleLit = isset($args[2]) ? self::compileTimeLong($args[2]) : null;
        $canFold = null !== $leftLit && null !== $rightLit && (!isset($args[2]) || null !== $scaleLit);
        if ($canFold && self::$compileTimeScaleKnown) {
            $scale = null !== $scaleLit ? $scaleLit : self::$compileTimeScale;
            /** @var string $result */
            $result = VmBcmath::$vmMethod($leftLit, $rightLit, $scale);

            return $context->builder->load($context->constantStringFromString($result));
        }

        Bcmath::ensureLinked($context);
        $left = JitStringBuiltinArg::lower($context, $args[0], $function, 0, $leftName);
        $right = JitStringBuiltinArg::lower($context, $args[1], $function, 1, $rightName);
        [$scale, $hasScale] = self::scaleAndFlag($context, $args, 2, $function);

        return $context->builder->call(
            $context->lookupFunction('__compiler_'.$function),
            $left,
            $right,
            $scale,
            $hasScale
        );
    }

    /** @param array<int, JITVariable> $args */
    private static function scaleAndFlag(Context $context, array $args, int $index, string $function): array
    {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        if (!isset($args[$index])) {
            return [$i64->constInt(0, true), $i32->constInt(-1, true)];
        }

        return [
            self::lowerScaleArg($context, $args[$index], $function, $index, 'scale'),
            $i32->constInt(1, true),
        ];
    }

    private static function lowerScaleArg(
        Context $context,
        JITVariable $arg,
        string $function,
        int $argIndex,
        string $name
    ): Value {
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException($function.'(): Argument #'.($argIndex + 1).' ($'.$name.') must be an integer in this compiler build');
    }

    private static function compileTimeString(JITVariable $arg): ?string
    {
        return JitStringArg::compileTimeLiteral($arg) ?? $arg->compileTimeString;
    }

    private static function compileTimeLong(JITVariable $arg): ?int
    {
        if (JITVariable::TYPE_NATIVE_LONG !== $arg->type || JITVariable::KIND_VALUE !== $arg->kind) {
            return null;
        }
        if (!method_exists($arg->value, 'isConstant') || !$arg->value->isConstant()) {
            return null;
        }

        return (int) $arg->value->getConstantValue();
    }

    private static function boxLong(Context $context, Value $long): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $long);

        return JitValueBox::pointer($context, $slot);
    }
}
