<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\Bcmath;
use PHPCompiler\JIT\Builtin\RoundingModeJit;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering helper for bcmath builtins via __compiler_bc* runtime bodies (#6100). */
final class JitBcmath
{
    public static int $compileTimeScale = 0;

    private static bool $compileTimeScaleKnown = true;

    public static function scale(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \ArgumentCountError('bcscale() expects at most 1 argument, '.\count($args).' given');
        }

        // Explicit null is the getter — same as omitted arg (php-src ?int $scale = null; #20974).
        $nullGetter = 1 === \count($args) && self::isNullScaleArg($args[0]);
        $effectiveArgc = $nullGetter ? 0 : \count($args);

        if (0 === $effectiveArgc && self::$compileTimeScaleKnown) {
            return self::boxLong($context, $context->getTypeFromString('int64')->constInt(self::$compileTimeScale, true));
        }

        if (1 === $effectiveArgc) {
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
        if (0 === $effectiveArgc) {
            $scale = $i64->constInt(0, true);
            $hasScale = $i64->constInt(-1, true);
        } else {
            [$scale, $hasScale] = self::lowerBcscaleNullableArg($context, $args[0]);
        }

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

        // Z_PARAM_STR before unimplemented — null TypeError under strict_types (#29977).
        if ($context->callerStrictTypes && self::isNullStringArg($args[0])) {
            return JitStringBuiltinArg::lower($context, $args[0], 'bcpow', 0, 'num');
        }
        if ($context->callerStrictTypes && self::isNullStringArg($args[1])) {
            return JitStringBuiltinArg::lower($context, $args[1], 'bcpow', 1, 'exponent');
        }
        JitStringBuiltinArg::lower($context, $args[0], 'bcpow', 0, 'num');
        JitStringBuiltinArg::lower($context, $args[1], 'bcpow', 1, 'exponent');
        $baseSoft = self::nullConstantAsEmptyString($args[0], $baseLit);
        $expSoft = self::nullConstantAsEmptyString($args[1], $expLit);
        if (
            null !== $baseSoft
            && null !== $expSoft
            && (!isset($args[2]) || null !== $scaleLit)
            && self::$compileTimeScaleKnown
        ) {
            $scale = null !== $scaleLit ? $scaleLit : self::$compileTimeScale;

            return $context->builder->load(
                $context->constantStringFromString(VmBcmath::pow($baseSoft, $expSoft, $scale))
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

        // Z_PARAM_STR before unimplemented — null TypeError under strict_types (#29977).
        $numLowered = JitStringBuiltinArg::lower($context, $args[0], 'bcsqrt', 0, 'num');
        if ($context->callerStrictTypes && self::isNullStringArg($args[0])) {
            return $numLowered;
        }
        $numSoft = self::nullConstantAsEmptyString($args[0], $numLit);
        if (
            null !== $numSoft
            && (!isset($args[1]) || null !== $scaleLit)
            && self::$compileTimeScaleKnown
        ) {
            $scale = null !== $scaleLit ? $scaleLit : self::$compileTimeScale;

            return $context->builder->load(
                $context->constantStringFromString(VmBcmath::sqrt($numSoft, $scale))
            );
        }

        throw new \LogicException('bcsqrt() not implemented for JIT with non-constant operands in this compiler build');
    }

    public static function powmod(Context $context, JITVariable ...$args): Value
    {
        // php-src bcmath.stub.php — at most 4 args; no RoundingMode (#26143).
        if (\count($args) < 3 || \count($args) > 4) {
            throw new \LogicException('bcpowmod() requires three or four arguments in this compiler build');
        }

        $baseLit = self::compileTimeString($args[0]);
        $expLit = self::compileTimeString($args[1]);
        $modLit = self::compileTimeString($args[2]);
        $scaleLit = isset($args[3]) ? self::compileTimeLong($args[3]) : null;
        $canFold = null !== $baseLit && null !== $expLit && null !== $modLit
            && (!isset($args[3]) || null !== $scaleLit);
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
        $i64 = $context->getTypeFromString('int64');
        $roundMode = $i64->constInt(0, false);
        $hasRoundMode = $i64->constInt(-1, true);

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
        // Enum-only — legacy int PHP_ROUND_* is TypeError under PROFILE≥8.4 (#28566).
        $modeLit = isset($args[2]) ? RoundingModeJit::compileTimeRoundMode($context, $args[2]) : null;
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
        // php-src bcmath.stub.php — RoundingMode only (#28566); reject legacy int / null.
        $mode = RoundingModeJit::compileTimeRoundMode($context, $arg);
        if (null !== $mode) {
            return $context->getTypeFromString('int64')->constInt($mode, false);
        }

        $given = self::compileTimeNonEnumModeTypeName($arg) ?? 'mixed';

        return self::emitRoundModeTypeError($context, $given);
    }

    /** Catchable TypeError for non-RoundingMode $mode under AOT/JIT (#28566; mirrors php_uname #28136). */
    private static function emitRoundModeTypeError(Context $context, string $given): Value
    {
        $message = sprintf(
            'bcround(): Argument #3 ($mode) must be of type RoundingMode, %s given',
            $given
        );
        ExceptionBridge::ensureLinked($context);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            TypeErrorRaise::ensureStandaloneBodies($context);
        }
        if (null !== TryCatchHelper::resolveThrowHandler($context)) {
            TryCatchHelper::emitCatchableClassError($context, 'TypeError', $message);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'bcround_mode_typeerror_dead');
        } else {
            TypeErrorRaise::emitRaise($context, $message);
            if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
                $context->builder->call($context->lookupFunction('phpc_jit_abort_if_pending_type_error'));
            } else {
                $context->builder->call($context->lookupFunction('abort'));
                $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
            }
            BasicBlockHelper::ensureOpenInsertBlock($context, 'bcround_mode_typeerror_dead');
        }

        return $context->getTypeFromString('int64')->constInt(0, false);
    }

    /** @return null|string Zend type name when $arg is a known non-RoundingMode compile-time value */
    private static function compileTimeNonEnumModeTypeName(JITVariable $arg): ?string
    {
        if (JITVariable::TYPE_NULL === $arg->type) {
            return 'null';
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type
            || JITVariable::TYPE_NATIVE_DOUBLE === $arg->type) {
            return 'int';
        }
        if (JITVariable::TYPE_VALUE === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            $const = $arg->value;
            if ($const instanceof Value && $const->isConstant()) {
                return 'int';
            }
        }
        if (null !== ($arg->compileTimeLong ?? null) || null !== self::compileTimeLong($arg)) {
            return 'int';
        }
        // Constant fetch of PHP_ROUND_* / other ints often arrives as VALUE box (#28566).
        if (null !== $arg->compileTimeConstantName) {
            return 'int';
        }

        return null;
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
        // php-src — at most 3 args (num1,num2,scale); no RoundingMode (#26143).
        if (\count($args) < 2 || \count($args) > 3) {
            throw new \LogicException(
                $function.'() requires two or three arguments in this compiler build'
            );
        }
        $leftLit = self::compileTimeString($args[0]);
        $rightLit = self::compileTimeString($args[1]);
        $scaleLit = isset($args[2]) ? self::compileTimeLong($args[2]) : null;
        $canFold = null !== $leftLit && null !== $rightLit
            && (!isset($args[2]) || null !== $scaleLit);
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
        $i64 = $context->getTypeFromString('int64');
        $roundMode = $i64->constInt(0, false);
        $hasRoundMode = $i64->constInt(-1, true);

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
    private static function scaleAndFlag(Context $context, array $args, int $index, string $function): array
    {
        $i64 = $context->getTypeFromString('int64');
        if (!isset($args[$index])) {
            return [$i64->constInt(0, true), $i64->constInt(-1, true)];
        }
        if (self::isNullScaleArg($args[$index])) {
            return [$i64->constInt(0, true), $i64->constInt(-1, true)];
        }
        if (JITVariable::TYPE_VALUE === $args[$index]->type) {
            return self::lowerBcscaleNullableArg($context, $args[$index]);
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

    /** Compile-time / typed null for bcscale(?int $scale = null) getter (#20974). */
    private static function isNullScaleArg(JITVariable $arg): bool
    {
        return JITVariable::TYPE_NULL === $arg->type || $arg->isNullConstant;
    }

    /**
     * Lower bcscale's optional scale to (long, hasScale) with runtime null → hasScale=-1 (#20974).
     *
     * @return array{0: Value, 1: Value}
     */
    private static function lowerBcscaleNullableArg(Context $context, JITVariable $arg): array
    {
        $i64 = $context->getTypeFromString('int64');
        if (self::isNullScaleArg($arg)) {
            return [$i64->constInt(0, true), $i64->constInt(-1, true)];
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::lowerBcscaleNullableValueBox($context, $arg);
        }

        return [
            self::lowerScaleArg($context, $arg, 'bcscale', 0, 'scale'),
            $i64->constInt(1, true),
        ];
    }

    /**
     * Runtime value-box: null → getter (hasScale=-1); else read long (#20974).
     *
     * @return array{0: Value, 1: Value}
     */
    private static function lowerBcscaleNullableValueBox(Context $context, JITVariable $arg): array
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $nullTy = $i8->constInt(VmVariable::TYPE_NULL, false);

        $nullBlock = BasicBlockHelper::append($context, 'bcscale_null');
        $intBlock = BasicBlockHelper::append($context, 'bcscale_int');
        $mergeBlock = BasicBlockHelper::append($context, 'bcscale_merge');

        $isNull = $context->builder->icmp(Builder::INT_EQ, $typeByte, $nullTy);
        $context->builder->branchIf($isNull, $nullBlock, $intBlock);

        $context->builder->positionAtEnd($nullBlock);
        $nullScale = $i64->constInt(0, true);
        $nullHas = $i64->constInt(-1, true);
        $nullEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($intBlock);
        $intScale = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $intHas = $i64->constInt(1, true);
        $intEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $scalePhi = $context->builder->phi($i64, 'bcscale_scale');
        $scalePhi->addIncoming($nullScale, $nullEnd);
        $scalePhi->addIncoming($intScale, $intEnd);
        $hasPhi = $context->builder->phi($i64, 'bcscale_has');
        $hasPhi->addIncoming($nullHas, $nullEnd);
        $hasPhi->addIncoming($intHas, $intEnd);

        return [$scalePhi, $hasPhi];
    }

    private static function compileTimeString(JITVariable $arg): ?string
    {
        return JitStringArg::compileTimeLiteral($arg) ?? $arg->compileTimeString;
    }

    /** Null Z_PARAM_STR soft-coerce → "" for compile-time fold after lower() (#29977). */
    private static function isNullStringArg(JITVariable $arg): bool
    {
        return JITVariable::TYPE_NULL === $arg->type || $arg->isNullConstant;
    }

    /**
     * After {@see JitStringBuiltinArg::lower} soft-null DEP, fold null constants as "".
     * Under caller strict_types lower() already aborted — caller should not use the result.
     */
    private static function nullConstantAsEmptyString(JITVariable $arg, ?string $lit): ?string
    {
        if (self::isNullStringArg($arg)) {
            return '';
        }

        return $lit;
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
        return RoundingModeJit::compileTimeRoundMode($context, $arg);
    }

    private static function boxLong(Context $context, Value $long): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $long);

        return JitValueBox::pointer($context, $slot);
    }
}
