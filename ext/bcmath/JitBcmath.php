<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\CompilerVersion;
use PHPCompiler\JIT\Builtin\Bcmath;
use PHPCompiler\JIT\Builtin\RoundingModeJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VmVariable;
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
        $scale = 0 === \count($args) ? $i64->constInt(0, true) : self::lowerScaleArg($context, $args[0], 'bcscale', 0, 'scale');
        $hasScale = 0 === \count($args) ? $i64->constInt(-1, true) : $i64->constInt(1, true);

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

    public static function divmod(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2 || \count($args) > 3) {
            throw new \LogicException('bcdivmod() requires two or three arguments in this compiler build');
        }

        $leftLit = self::compileTimeString($args[0]);
        $rightLit = self::compileTimeString($args[1]);
        $scaleLit = isset($args[2]) ? self::compileTimeLong($args[2]) : null;
        $canFold = null !== $leftLit && null !== $rightLit && (!isset($args[2]) || null !== $scaleLit);
        if ($canFold && self::$compileTimeScaleKnown) {
            $scale = null !== $scaleLit ? $scaleLit : self::$compileTimeScale;
            [$quotient, $remainder] = VmBcmath::divmod($leftLit, $rightLit, $scale);
            $ht = new HashTable();
            $qVar = new VmVariable();
            $qVar->string($quotient);
            $rVar = new VmVariable();
            $rVar->string($remainder);
            $ht->append($qVar);
            $ht->append($rVar);
            $cacheKey = 'bcdivmod:'.$leftLit.':'.$rightLit.':'.$scale;
            $global = $context->constantArrayFromVmHashTable($cacheKey, $ht);
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            JitValueBox::copyFromPointer($context, $slot, $context->builder->load($global));

            return $ptr;
        }

        throw new \LogicException('bcdivmod() not implemented for JIT with non-constant operands in this compiler build');
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

    public static function mod(Context $context, JITVariable ...$args): Value
    {
        return self::stringBinaryOp($context, 'bcmod', 'mod', $args, 'num1', 'num2');
    }

    public static function pow(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2 || \count($args) > 3) {
            throw new \LogicException('bcpow() requires two or three arguments in this compiler build');
        }

        $baseLit = self::compileTimeString($args[0]);
        $expLit = self::compileTimeString($args[1]);
        $scaleLit = isset($args[2]) ? self::compileTimeLong($args[2]) : null;
        $canFold = null !== $baseLit && null !== $expLit && (!isset($args[2]) || null !== $scaleLit);
        if ($canFold && self::$compileTimeScaleKnown) {
            $scale = null !== $scaleLit ? $scaleLit : self::$compileTimeScale;

            return $context->builder->load(
                $context->constantStringFromString(VmBcmath::pow($baseLit, $expLit, $scale))
            );
        }

        throw new \LogicException('bcpow() not implemented for JIT with non-constant operands in this compiler build');
    }

    public static function sqrt(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1 || \count($args) > 2) {
            throw new \LogicException('bcsqrt() requires one or two arguments in this compiler build');
        }

        $numLit = self::compileTimeString($args[0]);
        $scaleLit = isset($args[1]) ? self::compileTimeLong($args[1]) : null;
        $canFold = null !== $numLit && (!isset($args[1]) || null !== $scaleLit);
        if ($canFold && self::$compileTimeScaleKnown) {
            $scale = null !== $scaleLit ? $scaleLit : self::$compileTimeScale;

            return $context->builder->load(
                $context->constantStringFromString(VmBcmath::sqrt($numLit, $scale))
            );
        }

        throw new \LogicException('bcsqrt() not implemented for JIT with non-constant operands in this compiler build');
    }

    public static function powmod(Context $context, JITVariable ...$args): Value
    {
        $maxArgs = CompilerVersion::supportsRoundingModeEnum() ? 5 : 4;
        if (\count($args) < 3 || \count($args) > $maxArgs) {
            throw new \LogicException(
                5 === $maxArgs
                    ? 'bcpowmod() requires three to five arguments in this compiler build'
                    : 'bcpowmod() requires three or four arguments in this compiler build'
            );
        }

        $baseLit = self::compileTimeString($args[0]);
        $expLit = self::compileTimeString($args[1]);
        $modLit = self::compileTimeString($args[2]);
        $scaleLit = isset($args[3]) ? self::compileTimeLong($args[3]) : null;
        $modeLit = isset($args[4]) ? self::compileTimeRoundMode($context, $args[4]) : null;
        $canFold = null !== $baseLit && null !== $expLit && null !== $modLit
            && (!isset($args[3]) || null !== $scaleLit)
            && (!isset($args[4]) || null !== $modeLit);
        if ($canFold && self::$compileTimeScaleKnown) {
            $scale = null !== $scaleLit ? $scaleLit : self::$compileTimeScale;

            return $context->builder->load(
                $context->constantStringFromString(VmBcmath::powmod($baseLit, $expLit, $modLit, $scale, $modeLit))
            );
        }

        Bcmath::ensureLinked($context);
        $base = JitStringBuiltinArg::lower($context, $args[0], 'bcpowmod', 0, 'num');
        $exp = JitStringBuiltinArg::lower($context, $args[1], 'bcpowmod', 1, 'exponent');
        $mod = JitStringBuiltinArg::lower($context, $args[2], 'bcpowmod', 2, 'modulus');
        [$scale, $hasScale] = self::scaleAndFlag($context, $args, 3, 'bcpowmod');
        [$roundMode, $hasRoundMode] = self::roundModeAndFlag($context, $args, 4, 'bcpowmod');

        return $context->builder->call(
            $context->lookupFunction('__compiler_bcpowmod'),
            $base,
            $exp,
            $mod,
            $scale,
            $hasScale,
            $roundMode,
            $hasRoundMode
        );
    }

    public static function round(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1 || \count($args) > 3) {
            throw new \LogicException('bcround() requires one to three arguments in this compiler build');
        }

        $numLit = self::compileTimeString($args[0]);
        $precisionLit = isset($args[1]) ? self::compileTimeLong($args[1]) : 0;
        $modeLit = isset($args[2]) ? self::compileTimeRoundMode($context, $args[2]) : null;
        $canFold = null !== $numLit
            && (!isset($args[1]) || null !== $precisionLit)
            && (!isset($args[2]) || null !== $modeLit);
        if ($canFold) {
            $precision = null !== $precisionLit ? $precisionLit : 0;
            $mode = null !== $modeLit ? $modeLit : \PHPCompiler\ext\standard\StdlibConstants::PHP_ROUND_HALF_UP;

            return $context->builder->load(
                $context->constantStringFromString(VmBcmath::round($numLit, $precision, $mode))
            );
        }

        Bcmath::ensureLinked($context);
        $num = JitStringBuiltinArg::lower($context, $args[0], 'bcround', 0, 'num');
        $i64 = $context->getTypeFromString('int64');
        $precision = isset($args[1])
            ? self::lowerScaleArg($context, $args[1], 'bcround', 1, 'precision')
            : $i64->constInt(0, true);
        $mode = isset($args[2])
            ? self::lowerRoundModeArg($context, $args[2])
            : $i64->constInt(\PHPCompiler\ext\standard\StdlibConstants::PHP_ROUND_HALF_UP, false);

        return $context->builder->call(
            $context->lookupFunction('__compiler_bcround'),
            $num,
            $precision,
            $mode
        );
    }

    private static function lowerRoundModeArg(Context $context, JITVariable $arg): Value
    {
        $mode = self::compileTimeRoundMode($context, $arg);
        if (null !== $mode) {
            return $context->getTypeFromString('int64')->constInt($mode, false);
        }

        throw new \LogicException('bcround(): Argument #3 ($mode) must be a compile-time RoundingMode or int in this compiler build');
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
        $maxArgs = CompilerVersion::supportsRoundingModeEnum() ? 4 : 3;
        if (\count($args) < 2 || \count($args) > $maxArgs) {
            throw new \LogicException(
                4 === $maxArgs
                    ? $function.'() requires two to four arguments in this compiler build'
                    : $function.'() requires two or three arguments in this compiler build'
            );
        }
        $leftLit = self::compileTimeString($args[0]);
        $rightLit = self::compileTimeString($args[1]);
        $scaleLit = isset($args[2]) ? self::compileTimeLong($args[2]) : null;
        $modeLit = isset($args[3]) ? self::compileTimeRoundMode($context, $args[3]) : null;
        $canFold = null !== $leftLit && null !== $rightLit
            && (!isset($args[2]) || null !== $scaleLit)
            && (!isset($args[3]) || null !== $modeLit);
        if ($canFold && self::$compileTimeScaleKnown) {
            $scale = null !== $scaleLit ? $scaleLit : self::$compileTimeScale;
            /** @var string $result */
            $result = VmBcmath::$vmMethod($leftLit, $rightLit, $scale, $modeLit);

            return $context->builder->load($context->constantStringFromString($result));
        }

        Bcmath::ensureLinked($context);
        $left = JitStringBuiltinArg::lower($context, $args[0], $function, 0, $leftName);
        $right = JitStringBuiltinArg::lower($context, $args[1], $function, 1, $rightName);
        [$scale, $hasScale] = self::scaleAndFlag($context, $args, 2, $function);
        [$roundMode, $hasRoundMode] = self::roundModeAndFlag($context, $args, 3, $function);

        return $context->builder->call(
            $context->lookupFunction('__compiler_'.$function),
            $left,
            $right,
            $scale,
            $hasScale,
            $roundMode,
            $hasRoundMode
        );
    }

    /** @param array<int, JITVariable> $args */
    private static function roundModeAndFlag(Context $context, array $args, int $index, string $function): array
    {
        $i64 = $context->getTypeFromString('int64');
        if (!isset($args[$index])) {
            return [$i64->constInt(0, false), $i64->constInt(-1, true)];
        }
        if (!CompilerVersion::supportsRoundingModeEnum()) {
            throw new \LogicException($function.'() accepts at most three arguments in this compiler build');
        }

        return [
            self::lowerRoundModeArg($context, $args[$index]),
            $i64->constInt(1, true),
        ];
    }

    /** @param array<int, JITVariable> $args */
    private static function scaleAndFlag(Context $context, array $args, int $index, string $function): array
    {
        $i64 = $context->getTypeFromString('int64');
        if (!isset($args[$index])) {
            return [$i64->constInt(0, true), $i64->constInt(-1, true)];
        }

        return [
            self::lowerScaleArg($context, $args[$index], $function, $index, 'scale'),
            $i64->constInt(1, true),
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
        if (null !== ($arg->compileTimeLong ?? null)) {
            return (int) $arg->compileTimeLong;
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            if (JITVariable::KIND_VALUE === $arg->kind) {
                $const = $arg->value;
                if ($const instanceof Value && $const->isConstant()) {
                    return (int) $const->constInt();
                }
                if (method_exists($const, 'isConstant') && $const->isConstant()) {
                    return (int) $const->getConstantValue();
                }
            }
            if (JITVariable::KIND_VARIABLE === $arg->kind) {
                $const = $arg->value;
                if ($const instanceof Value && $const->isConstant()) {
                    return (int) $const->constInt();
                }
            }
        }
        if (JITVariable::TYPE_NATIVE_DOUBLE === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            $const = $arg->value;
            if ($const instanceof Value && $const->isConstant()) {
                return (int) $const->constDouble();
            }
        }
        if (JITVariable::TYPE_VALUE === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            $const = $arg->value;
            if ($const instanceof Value && $const->isConstant()) {
                return (int) $const->constInt();
            }
        }
        $numeric = JitStringArg::compileTimeLiteral($arg);
        if (null !== $numeric && is_numeric($numeric)) {
            return (int) $numeric;
        }

        return null;
    }

    private static function compileTimeRoundMode(Context $context, JITVariable $arg): ?int
    {
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type && JITVariable::KIND_VALUE === $arg->kind
            && method_exists($arg->value, 'isConstant') && $arg->value->isConstant()) {
            return (int) $arg->value->getConstantValue();
        }

        return RoundingModeJit::compileTimeRoundMode($context, $arg);
    }

    private static function boxLong(Context $context, Value $long): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $long);

        return JitValueBox::pointer($context, $slot);
    }
}
