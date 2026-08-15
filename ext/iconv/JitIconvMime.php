<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringIconvMime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for iconv_mime_decode() (#27424; php-src ext/iconv/iconv.c).
 */
final class JitIconvMime
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('iconv_mime_decode() requires between 1 and 3 arguments');
        }

        $folded = self::tryCompileTimeFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        $encoded = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'iconv_mime_decode', 0, 'string')
            : JitStringBuiltinArg::lowerZparamStr($context, $args[0], 'iconv_mime_decode', 0, 'string');

        $i64 = $context->getTypeFromString('int64');
        $mode = $i64->constInt(0, false);
        if ($argc >= 2) {
            $mode = JitLongArg::lower($context, $args[1], 'iconv_mime_decode() mode');
        }

        if ($argc >= 3) {
            $charsetIsNull = JITVariable::TYPE_NULL === $args[2]->type || $args[2]->isNullConstant;
            $charset = $charsetIsNull
                ? $context->builder->load($context->constantStringFromString(''))
                : ($context->callerStrictTypes
                    ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[2], 'iconv_mime_decode', 2, 'encoding')
                    : JitStringBuiltinArg::lowerZparamStr($context, $args[2], 'iconv_mime_decode', 2, 'encoding'));
        } else {
            $charset = $context->builder->load($context->constantStringFromString(''));
        }

        StringIconvMime::ensureLinked($context);
        $result = $context->builder->call(
            $context->lookupFunction('__compiler_iconv_mime_decode'),
            $encoded,
            $mode,
            $charset
        );

        return self::materializeStringOrFalse($context, $result);
    }

    /**
     * @param JITVariable[] $args
     */
    private static function tryCompileTimeFold(Context $context, array $args): ?Value
    {
        $encoded = JitStringBuiltinArg::compileTimeLiteral($args[0]);
        if (null === $encoded) {
            return null;
        }
        $mode = 0;
        if (isset($args[1]) && JITVariable::TYPE_NULL !== $args[1]->type) {
            if (null === $args[1]->compileTimeLong) {
                return null;
            }
            $mode = (int) $args[1]->compileTimeLong;
        }
        $charset = null;
        if (isset($args[2])) {
            if (JITVariable::TYPE_NULL === $args[2]->type || $args[2]->isNullConstant) {
                $charset = null;
            } else {
                $charset = JitStringBuiltinArg::compileTimeLiteral($args[2]);
                if (null === $charset) {
                    return null;
                }
            }
        }

        $result = VmIconvMime::mimeDecode($encoded, $mode, $charset, null);
        if (false === $result) {
            $slot = JitValueBox::alloc($context);
            $i1 = $context->getTypeFromString('int1');
            JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

            return JitValueBox::pointer($context, $slot);
        }

        $strPtr = $context->builder->load($context->constantStringFromString($result));

        return self::materializeStringOrFalse($context, $strPtr);
    }

    private static function materializeStringOrFalse(Context $context, Value $contents): Value
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $failed = $context->builder->icmp(Builder::INT_EQ, $contents, $strPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'iconv_mime_fail');
        $okBlock = BasicBlockHelper::append($context, 'iconv_mime_ok');
        $doneBlock = BasicBlockHelper::append($context, 'iconv_mime_done');
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
