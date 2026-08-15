<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Iconv as IconvRuntimeLink;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for iconv() (issue #6009, #6251; php-src ext/iconv/iconv.c). */
final class JitIconv
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (3 !== \count($args)) {
            throw new \LogicException('iconv() requires exactly three arguments');
        }

        // Z_PARAM_STR $string — soft-null DEP+coerce (#21197).
        // Encoding args: TypeError on PROFILE=8.4 / strict_types (#19387); soft-null DEP on default (#31309).
        // Do not map null→'' before the fold guard for encoding args — that incorrectly constant-folds
        // iconv(null, …) under AOT (#19387).
        $fromIsNull = self::encodingArgIsNullConstant($args[0]);
        $toIsNull = self::encodingArgIsNullConstant($args[1]);
        $inputIsNull = JITVariable::TYPE_NULL === $args[2]->type || $args[2]->isNullConstant;
        $rejectNullZparam = $context->callerStrictTypes
            || JitStringBuiltinArg::requiresZparamStrStrictNullOnForwardProfile();
        $fromLit = $fromIsNull ? null : JitStringBuiltinArg::compileTimeLiteral($args[0]);
        $toLit = $toIsNull ? null : JitStringBuiltinArg::compileTimeLiteral($args[1]);
        // Soft-null input: fold null→'' outside strict_types (#21197).
        // Soft-null encodings on default profile: fold null→'' with DEP (#31309).
        if (!$rejectNullZparam) {
            if ($fromIsNull) {
                $fromLit = '';
            }
            if ($toIsNull) {
                $toLit = '';
            }
        }
        $inputLit = $inputIsNull
            ? ($context->callerStrictTypes ? null : '')
            : JitStringBuiltinArg::compileTimeLiteral($args[2]);
        if (
            null !== $fromLit
            && null !== $toLit
            && null !== $inputLit
            && !($rejectNullZparam && ($fromIsNull || $toIsNull))
            && !($context->callerStrictTypes && $inputIsNull)
            && null !== CharsetEngine::parseEncodingSpec(VmIconv::resolveIconvEncoding($fromLit, true))
            && null !== CharsetEngine::parseEncodingSpec(VmIconv::resolveIconvEncoding($toLit, false))
        ) {
            if ($fromIsNull && !$rejectNullZparam) {
                JitStringBuiltinArg::emitNullStringParamDeprecation($context, 'iconv', 0, 'from_encoding');
            }
            if ($toIsNull && !$rejectNullZparam) {
                JitStringBuiltinArg::emitNullStringParamDeprecation($context, 'iconv', 1, 'to_encoding');
            }
            if ($inputIsNull) {
                JitStringBuiltinArg::emitNullStringParamDeprecation($context, 'iconv', 2, 'string');
            }

            return self::foldCompileTime($context, $fromLit, $toLit, $inputLit);
        }

        IconvRuntimeLink::ensureLinked($context);

        // Encoding args: Z_PARAM_STR TypeError on PROFILE=8.4 (#19387); soft-null DEP otherwise (#31309).
        // $string: soft-null DEP+coerce (#21197).
        $from = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'iconv', 0, 'from_encoding')
            : ($rejectNullZparam
                ? JitStringBuiltinArg::lowerZparamStr($context, $args[0], 'iconv', 0, 'from_encoding')
                : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'iconv', 0, 'from_encoding'));
        $to = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[1], 'iconv', 1, 'to_encoding')
            : ($rejectNullZparam
                ? JitStringBuiltinArg::lowerZparamStr($context, $args[1], 'iconv', 1, 'to_encoding')
                : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'iconv', 1, 'to_encoding'));
        $input = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[2], 'iconv', 2, 'string')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[2], 'iconv', 2, 'string');

        $result = $context->builder->call(
            $context->lookupFunction('__compiler_iconv'),
            $from,
            $to,
            $input
        );

        return self::materializeStringOrFalse($context, $result);
    }

    private static function encodingArgIsNullConstant(JITVariable $arg): bool
    {
        return JITVariable::TYPE_NULL === $arg->type || $arg->isNullConstant;
    }

    private static function foldCompileTime(Context $context, string $from, string $to, string $input): Value
    {
        $converted = VmIconv::iconv($from, $to, $input);
        if (false === $converted) {
            // Match runtime materializeStringOrFalse failure shape (value-box false).
            $slot = JitValueBox::alloc($context);
            $i1 = $context->getTypeFromString('int1');
            JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

            return JitValueBox::pointer($context, $slot);
        }

        // constantFromString() is a C-string global — must box as __string__ like the runtime path
        // so AOT can infer the builtin return type (#21197; unblocks empty/null soft-null folds).
        $strPtr = $context->builder->load($context->constantStringFromString($converted));

        return self::materializeStringOrFalse($context, $strPtr);
    }

    private static function materializeStringOrFalse(Context $context, Value $contents): Value
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $failed = $context->builder->icmp(Builder::INT_EQ, $contents, $strPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'iconv_fail');
        $okBlock = BasicBlockHelper::append($context, 'iconv_ok');
        $doneBlock = BasicBlockHelper::append($context, 'iconv_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $contents
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
