<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringSubstrCount;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Value;

/**
 * substr_count() for two strings with optional offset and length (php-src ext/standard/string.c).
 *
 * VM: {@see VmString::substr_count()}; JIT/AOT: {@see StringSubstrCount} → SubstrCountJitHelper PHP (#14691).
 */
final class substr_count extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/string.stub.php — ArgumentCountError (#28311).
        $this->requireArgCountRange($frame, 'substr_count', 2, 4);
        $argc = \count($frame->calledArgs);
        if (null === $frame->returnVar) {
            return;
        }
        $haystack = self::vmHaystackArg($frame);
        $needle = self::vmNeedleArg($frame);
        $offset = 0;
        if ($argc >= 3) {
            // Z_PARAM_LONG $offset — soft-null DEP+coerce on 8.4 (#21657; peer chr/mktime).
            $offset = VmMath::parseChrCodepointForFrame($frame, 2, 'substr_count', 3, 'offset');
        }
        $length = null;
        if (4 === $argc) {
            $length = VmMath::parseNullableIntBuiltinArgForFrame($frame, 3, 'substr_count', 4, 'length');
        }
        $frame->returnVar->int(
            VmString::substr_count($haystack, $needle, $offset, $length)
        );
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        // Catchable ArgumentCountError (AOT) — peer strpos #21964 / #28311.
        if (!$this->requireArgCountRangeJit($context, $args, 'substr_count', 2, 4)) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        $argc = \count($args);

        // Compile-time fold (#21657) — host-evaluate when operands are literals (AOT verify).
        $folded = self::tryFoldCompileTime($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        StringSubstrCount::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $fn = $context->lookupFunction('phpc_substr_count');
        $hay = self::jitHaystackArg($context, $args[0]);
        // Z_PARAM_STR $needle — soft-null DEP+coerce then empty ValueError (#29421; peer str_increment #26264).
        $needle = self::jitNeedleArg($context, $args[1]);
        JitStringBuiltinArg::rejectEmpty(
            $context,
            $args[1],
            $needle,
            VmString::emptyStringArgValueErrorMessage('substr_count', 1, 'needle')
        );
        // Z_PARAM_LONG $offset — soft-null DEP+coerce on 8.4 (#21657; peer chr/mktime).
        $offset = $argc >= 3
            ? JitChr::lowerZParamLongArg($context, $args[2], 'substr_count', 3, 'offset')
            : $i64->constInt(0, false);

        if (4 !== $argc) {
            return $context->builder->call(
                $fn,
                $hay,
                $needle,
                $offset,
                $i64->constInt(0, false),
                $i32->constInt(0, false)
            );
        }

        if (JITVariable::TYPE_NATIVE_LONG === $args[3]->type
            || JITVariable::TYPE_STRING === $args[3]->type) {
            $length = JitIntdiv::lowerNullableIntBuiltinArgForCaller($context, $args[3], 'substr_count', 4, 'length');

            return $context->builder->call(
                $fn,
                $hay,
                $needle,
                $offset,
                $length,
                $i32->constInt(1, false)
            );
        }

        if (JITVariable::TYPE_VALUE !== $args[3]->type) {
            throw new \LogicException('substr_count() length must be an integer or null in this compiler build');
        }

        return $this->jitSubstrCountNullableLength($context, $fn, $hay, $needle, $offset, $args[3]);
    }

    private function jitSubstrCountNullableLength(
        Context $context,
        Value $fn,
        Value $hay,
        Value $needle,
        Value $offset,
        JITVariable $lengthArg
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $lengthArg);
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load($context->builder->structGep($valuePtr, $valueMap['type']));
        $i8 = $context->getTypeFromString('int8');
        $isNull = $context->builder->icmp(
            \PHPLLVM\Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_NULL, false)
        );

        $nullBlock = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'substr_count_len_null');
        $lenBlock = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'substr_count_len_value');
        $done = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'substr_count_len_done');
        $context->builder->branchIf($isNull, $nullBlock, $lenBlock);

        $context->builder->positionAtEnd($nullBlock);
        $nullResult = $context->builder->call(
            $fn,
            $hay,
            $needle,
            $offset,
            $i64->constInt(0, false),
            $i32->constInt(0, false)
        );
        $nullEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($lenBlock);
        $lenResult = $context->builder->call(
            $fn,
            $hay,
            $needle,
            $offset,
            JitIntdiv::lowerNullableIntBuiltinArgForCaller($context, $lengthArg, 'substr_count', 4, 'length'),
            $i32->constInt(1, false)
        );
        $lenEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($i64);
        $phi->addIncoming($nullResult, $nullEnd);
        $phi->addIncoming($lenResult, $lenEnd);

        return $phi;
    }

    /**
     * Compile-time fold for literal operands — emit soft-null DEP then host-evaluate (#21657).
     *
     * @param list<JITVariable> $args
     */
    private static function tryFoldCompileTime(Context $context, array $args): ?Value
    {
        $argc = \count($args);
        $hayNull = self::isCompileTimeNull($args[0]);
        $hayLit = $hayNull ? '' : JitStringArg::compileTimeLiteral($args[0]);
        if (null === $hayLit) {
            return null;
        }
        $needleNull = self::isCompileTimeNull($args[1]);
        $needleLit = $needleNull ? '' : JitStringArg::compileTimeLiteral($args[1]);
        if (null === $needleLit) {
            return null;
        }
        // Empty needle is a runtime ValueError (null emits DEP first) — do not host-fold (#29421).
        if ('' === $needleLit) {
            return null;
        }
        $offset = 0;
        $offsetNull = false;
        if ($argc >= 3) {
            $offsetNull = self::isCompileTimeNull($args[2]);
            if ($offsetNull) {
                if ($context->callerStrictTypes) {
                    return null;
                }
            } elseif (null === $args[2]->compileTimeLong) {
                return null;
            } else {
                $offset = $args[2]->compileTimeLong;
            }
        }
        $length = null;
        if (4 === $argc) {
            if (self::isCompileTimeNull($args[3])) {
                $length = null;
            } elseif (null !== $args[3]->compileTimeLong) {
                $length = $args[3]->compileTimeLong;
            } else {
                return null;
            }
        }
        if ($hayNull) {
            if ($context->callerStrictTypes) {
                return null; // strict_types TypeError — do not fold (#29808)
            }
            JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'substr_count', 0, 'haystack');
        }
        if ($offsetNull) {
            JitIntdiv::emitNullIntDeprecation($context, 'substr_count', 3, 'offset');
        }

        return $context->getTypeFromString('int64')->constInt(
            VmString::substr_count($hayLit, $needleLit, $offset, $length),
            true
        );
    }

    private static function isCompileTimeNull(JITVariable $arg): bool
    {
        return JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false);
    }

    /**
     * php-src Z_PARAM_STR haystack — strict TypeError or soft-null (#21196, #29808; sibling #21189).
     */
    private static function vmHaystackArg(Frame $frame): string
    {
        return VmString::trimFamilyStringArgForFrame($frame, 0, 'substr_count', 0, 'haystack');
    }

    private static function jitHaystackArg(Context $context, JITVariable $arg): Value
    {
        return $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $arg, 'substr_count', 0, 'haystack')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $arg, 'substr_count', 0, 'haystack');
    }

    /**
     * php-src Z_PARAM_STR $needle — soft-null DEP+coerce then empty ValueError (#29421; peer str_increment #26264).
     *
     * Older #18347 short-circuited null→"" without the null-to-string deprecation Zend emits since 8.1.
     */
    private static function vmNeedleArg(Frame $frame): string
    {
        return VmString::trimFamilyStringArgForFrame($frame, 1, 'substr_count', 1, 'needle');
    }

    private static function jitNeedleArg(Context $context, JITVariable $arg): Value
    {
        return $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $arg, 'substr_count', 1, 'needle')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $arg, 'substr_count', 1, 'needle');
    }
}
