<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbPreferredMimeNameRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT for mb_preferred_mime_name() (php-src ext/mbstring/mbstring.c; #13100, #34298).
 *
 * Compile-time fold for string literals; runtime via NestedJIT {@see MbPreferredMimeNameJitHelper}.
 * Peer {@see JitMbEncodingRegistry} / {@see JitMbChrOrd}.
 */
final class JitMbPreferredMimeName
{
    /**
     * @param list<JITVariable> $args
     */
    public static function invoke(Context $context, array $args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            throw new \LogicException('mb_preferred_mime_name() requires exactly one argument');
        }

        $folded = self::tryCompileTimeFold($context, $args[0]);
        if (null !== $folded) {
            return $folded;
        }

        $encoding = JitStringBuiltinArg::lower(
            $context,
            $args[0],
            'mb_preferred_mime_name',
            0,
            'encoding'
        );

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbPreferredMimeNameRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }

        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            MbPreferredMimeNameRuntime::preferredHelper($context),
            [$encoding]
        );

        return self::boxStringOrFalse($context, $raw);
    }

    private static function tryCompileTimeFold(Context $context, JITVariable $arg): ?Value
    {
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return null;
        }
        $encodingLit = JitStringArg::compileTimeLiteral($arg);
        if (null === $encodingLit) {
            return null;
        }

        $canonical = MbstringEncodingRegistry::resolve($encodingLit);
        if (null === $canonical) {
            return JitMbEncodingRegistry::emitPreferredMimeValueError(
                $context,
                sprintf(
                    'mb_preferred_mime_name(): Argument #1 ($encoding) must be a valid encoding, "%s" given',
                    $encodingLit
                )
            );
        }
        $mime = MbstringEncodingRegistry::preferredMimeName($canonical);
        if (false === $mime) {
            return $context->constantFromBool(false);
        }

        return self::materializeString($context, $mime);
    }

    /**
     * NestedJIT string|false → `__value__*` (peer JitMbChrOrd / #34250).
     */
    private static function boxStringOrFalse(Context $context, Value $raw): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_preferred_mime_box');
        $i32 = $context->getTypeFromString('int32');
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $isMiss = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
        $missBb = BasicBlockHelper::append($context, 'mb_preferred_mime_miss');
        $hitBb = BasicBlockHelper::append($context, 'mb_preferred_mime_hit');
        $doneBb = BasicBlockHelper::append($context, 'mb_preferred_mime_done');
        $context->builder->branchIf($isMiss, $missBb, $hitBb);

        $context->builder->positionAtEnd($missBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $ptr,
            $i32->constInt(0, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($hitBb);
        $strPtr = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $strPtr);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $owned
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $ptr;
    }

    private static function materializeString(Context $context, string $str): Value
    {
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $context->builder->load($context->constantStringFromString($str))
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }
}
