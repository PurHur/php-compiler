<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringSubstrCompare;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * substr_compare() — compare haystack slice to needle (subset of PHP; issue #2400).
 * JIT lowers via {@see StringSubstrCompare} + {@see SubstrCompareJitHelper} (VmString parity; no phpc_substr_compare.c).
 */
final class substr_compare extends Internal
{
    public function __construct()
    {
        parent::__construct('substr_compare');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/string.c — ArgumentCountError (#21769).
        $this->requireArgCountRange($frame, 'substr_compare', 3, 5);
        $argc = \count($frame->calledArgs);
        $haystack = self::vmStringArg($frame, 0, 'haystack');
        $needle = self::vmStringArg($frame, 1, 'needle');
        // Z_PARAM_LONG $offset — soft-null DEP+coerce (php-src string.c; #29504; peer substr_count #21657).
        $offsetInt = VmMath::parseChrCodepointForFrame($frame, 2, 'substr_compare', 3, 'offset');
        $length = null;
        if ($argc >= 4) {
            $lengthArg = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_NULL !== $lengthArg->type) {
                $length = self::requireIntArg($frame->calledArgs[3], 'substr_compare', 4, 'length');
            }
        }
        // Z_PARAM_BOOL $case_insensitive — strict TypeError; soft-null DEP+coerce (#29756).
        $caseInsensitive = false;
        if (5 === $argc) {
            $caseInsensitive = VmMath::parseBoolBuiltinArgForFrame(
                $frame,
                4,
                'substr_compare',
                5,
                'case_insensitive'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmString::substr_compare(
            $haystack,
            $needle,
            $offsetInt,
            $length,
            $caseInsensitive
        ));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (!$this->requireArgCountRangeJit($context, $args, 'substr_compare', 3, 5)) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        $argc = \count($args);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');

        // Compile-time soft-null fold (#21515) — DEP + host-evaluate when operands are literals.
        $folded = self::tryFoldCompileTimeSoftNull($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        // Soft-null before ensureLinked — helper link clears insert block (#21515 / peer #20007).
        if (self::isCompileTimeNull($args[0])) {
            JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'substr_compare', 0, 'haystack');
            if ($context->callerStrictTypes) {
                return $i64->constInt(0, false);
            }
        } elseif (self::isCompileTimeNull($args[1])) {
            JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'substr_compare', 1, 'needle');
            if ($context->callerStrictTypes) {
                return $i64->constInt(0, false);
            }
        }

        StringSubstrCompare::ensureLinked($context);
        $lengthVal = $i64->constInt(-1, false);
        if ($argc >= 4) {
            if (JITVariable::TYPE_VALUE === $args[3]->type && $args[3]->isNullConstant) {
                $lengthVal = $i64->constInt(-1, false);
            } else {
                $lengthVal = self::lowerStrictIntArg($context, $args[3], 'substr_compare', 4, 'length');
            }
        }
        // Z_PARAM_BOOL $case_insensitive — strict TypeError; soft-null DEP+coerce (#29756).
        // Compile-time null under strict: emit catchable TypeError and stop (do not continue
        // after terminator — lowerCoerceZParamBool would soft-null-DEP into a dead block).
        $ci = $i32->constInt(0, false);
        if (5 === $argc) {
            if ($context->callerStrictTypes && self::isCompileTimeNull($args[4])) {
                ExceptionBridge::emitTypeErrorAndAbort(
                    $context,
                    'substr_compare(): Argument #5 ($case_insensitive) must be of type bool, null given'
                );

                return $i64->constInt(0, false);
            }
            $ci = $context->builder->zExt(
                JitBoolArg::lowerCoerceZParamBool(
                    $context,
                    $args[4],
                    'substr_compare',
                    'case_insensitive',
                    5
                ),
                $i32
            );
        }
        // Soft-null on forward profile — Zend 8.4 deprecate+coerce (#21515, reverts #20164 TypeError).
        $p0 = $this->stringDataPtr($context, self::jitStringArg($context, $args[0], 0, 'haystack'));
        $p1 = $this->stringDataPtr($context, self::jitStringArg($context, $args[1], 1, 'needle'));
        // Z_PARAM_LONG $offset — soft-null DEP+coerce (#29504; peer substr_count #21657).
        $offset = JitChr::lowerZParamLongArg($context, $args[2], 'substr_compare', 3, 'offset');
        $fn = $context->lookupFunction('substr_compare');
        $raw = $context->builder->call($fn, $p0, $p1, $offset, $lengthVal, $ci);

        return $context->builder->sExt($raw, $i64);
    }

    /**
     * Compile-time soft-null fold — emit DEP then host-evaluate when all operands are literals (#21515 / #29504).
     */
    private static function tryFoldCompileTimeSoftNull(Context $context, array $args): ?Value
    {
        if ($context->callerStrictTypes) {
            return null;
        }
        $hayNull = self::isCompileTimeNull($args[0]);
        $needleNull = self::isCompileTimeNull($args[1]);
        $offsetNull = self::isCompileTimeNull($args[2]);
        if (!$hayNull && !$needleNull && !$offsetNull) {
            return null;
        }
        $hayLit = $hayNull ? '' : JitStringArg::compileTimeLiteral($args[0]);
        $needleLit = $needleNull ? '' : JitStringArg::compileTimeLiteral($args[1]);
        if (null === $hayLit || null === $needleLit) {
            return null;
        }
        if ($offsetNull) {
            $offset = 0;
        } elseif (null === $args[2]->compileTimeLong) {
            return null;
        } else {
            $offset = $args[2]->compileTimeLong;
        }
        $argc = \count($args);
        $length = null;
        if ($argc >= 4) {
            if (JITVariable::TYPE_VALUE === $args[3]->type && ($args[3]->isNullConstant ?? false)) {
                $length = null;
            } elseif (null !== $args[3]->compileTimeLong) {
                $length = $args[3]->compileTimeLong;
            } else {
                return null;
            }
        }
        $caseInsensitive = false;
        if (5 === $argc) {
            if (null === $args[4]->compileTimeLong) {
                return null;
            }
            $caseInsensitive = 0 !== $args[4]->compileTimeLong;
        }
        if ($hayNull) {
            JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'substr_compare', 0, 'haystack');
        }
        if ($needleNull) {
            JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'substr_compare', 1, 'needle');
        }
        if ($offsetNull) {
            JitIntdiv::emitNullIntDeprecation($context, 'substr_compare', 3, 'offset');
        }

        return $context->getTypeFromString('int64')->constInt(
            VmString::substr_compare($hayLit, $needleLit, $offset, $length, $caseInsensitive),
            true
        );
    }

    private static function isCompileTimeNull(JITVariable $arg): bool
    {
        return JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false);
    }

    /**
     * @throws \TypeError
     */
    private static function requireIntArg(Variable $var, string $function, int $argIndex, string $paramName): int
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #%d ($%s) must be of type int, %s given',
                $function,
                $argIndex,
                $paramName,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }

        return $var->toInt();
    }

    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'substr_compare', $paramName)->toString();
        }

        // Soft-null on forward profile — Zend 8.4 deprecate+coerce (#21515, peers strncmp #21317).
        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[$argIndex],
            'substr_compare',
            $argIndex,
            $paramName
        );
    }

    private static function jitStringArg(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $paramName
    ): Value {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'substr_compare',
                $argIndex,
                $paramName
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'substr_compare',
            $argIndex,
            $paramName
        );
    }

    private static function lowerStrictIntArg(
        Context $context,
        JITVariable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): Value {
        if (($arg->type & JITVariable::IS_NATIVE_ARRAY) || JITVariable::TYPE_HASHTABLE === $arg->type) {
            self::emitIntTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'array');

            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            self::emitIntTypeErrorAndAbort(
                $context,
                $function,
                $argIndex,
                $paramName,
                JitOperandTypeLabel::givenLabel($context, $arg)
            );

            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::lowerBoxedStrictIntArg($context, $arg, $function, $argIndex, $paramName);
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $arg->type) {
            self::emitIntTypeErrorAndAbort(
                $context,
                $function,
                $argIndex,
                $paramName,
                JitOperandTypeLabel::givenLabel($context, $arg)
            );

            return $context->getTypeFromString('int64')->constInt(0, false);
        }

        return $context->helper->loadValue($arg);
    }

    private static function lowerBoxedStrictIntArg(
        Context $context,
        JITVariable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): Value {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $arrayTy = $i8->constInt(Variable::TYPE_ARRAY, false);
        $objectTy = $i8->constInt(Variable::TYPE_OBJECT, false);
        $enumCaseTy = $i8->constInt(Variable::TYPE_ENUM_CASE, false);
        $intTy = $i8->constInt(Variable::TYPE_INTEGER, false);

        $okBlock = BasicBlockHelper::append($context, 'substr_compare_int_ok');
        $arrayBlock = BasicBlockHelper::append($context, 'substr_compare_int_array');
        $rejectBlock = BasicBlockHelper::append($context, 'substr_compare_int_reject');
        $coerceBlock = BasicBlockHelper::append($context, 'substr_compare_int_coerce');

        $isArray = $context->builder->icmp(Builder::INT_EQ, $typeByte, $arrayTy);
        $context->builder->branchIf($isArray, $arrayBlock, $okBlock);

        $context->builder->positionAtEnd($arrayBlock);
        self::emitIntTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'array');

        $context->builder->positionAtEnd($okBlock);
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeByte, $objectTy);
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $typeByte, $enumCaseTy);
        $isObjOrEnum = $context->builder->or($isObject, $isEnumCase);
        $context->builder->branchIf($isObjOrEnum, $rejectBlock, $coerceBlock);

        $context->builder->positionAtEnd($rejectBlock);
        self::emitIntTypeErrorAndAbort(
            $context,
            $function,
            $argIndex,
            $paramName,
            JitOperandTypeLabel::givenLabel($context, $arg)
        );

        $context->builder->positionAtEnd($coerceBlock);
        $isInt = $context->builder->icmp(Builder::INT_EQ, $typeByte, $intTy);
        $intOkBlock = BasicBlockHelper::append($context, 'substr_compare_int_read');
        $stringErrBlock = BasicBlockHelper::append($context, 'substr_compare_int_string_err');
        $context->builder->branchIf($isInt, $intOkBlock, $stringErrBlock);

        $context->builder->positionAtEnd($stringErrBlock);
        self::emitIntTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'string');

        $context->builder->positionAtEnd($intOkBlock);

        return $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $valuePtr
        );
    }

    private static function emitIntTypeErrorAndAbort(
        Context $context,
        string $function,
        int $argIndex,
        string $paramName,
        string $given
    ): void {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise(
            $context,
            sprintf(
                '%s(): Argument #%d ($%s) must be of type int, %s given',
                $function,
                $argIndex,
                $paramName,
                $given
            )
        );
        $context->builder->call($context->lookupFunction('abort'));
    }
}
