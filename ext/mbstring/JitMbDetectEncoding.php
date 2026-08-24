<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbDetectEncodingRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helpers for mb_detect_encoding() (#3075, #34358).
 *
 * Compile-time fold when all arguments are literals; runtime haystack via NestedJIT
 * {@see MbDetectEncodingJitHelper} (peer {@see JitMbScrub} / {@see JitMbConvertEncoding}).
 */
final class JitMbDetectEncoding
{
    /**
     * @param list<JITVariable> $args
     */
    public static function invoke(Context $context, array $args): Value
    {
        $folded = self::tryCompileTimeFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        $order = self::compileTimeEncodingList($args, 1);
        if (null === $order) {
            throw new \LogicException(
                'mb_detect_encoding() encodings must be a compile-time array or string literal in this compiler build'
            );
        }
        self::assertSupportedOrder($order);

        $strict = self::runtimeStrictLiteral($args, 2);

        $str = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[0],
            'mb_detect_encoding',
            0,
            'string'
        );

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbDetectEncodingRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_detect_encoding_runtime');

        $orderPtr = $context->builder->load($context->constantStringFromString(implode(',', $order)));
        $strictVal = $context->constantFromBool($strict);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            MbDetectEncodingRuntime::detectHelper($context),
            [$str, $orderPtr, $strictVal]
        );

        return self::boxStringOrFalse($context, $raw);
    }

    /**
     * @param JITVariable[] $args
     */
    public static function tryCompileTimeFold(Context $context, array $args): ?Value
    {
        if (!isset($args[0])) {
            return null;
        }
        // Soft-null DEP+coerce on 8.4 (php-src mbstring.c; #21516, reverts #20225 TypeError).
        if (JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant) {
            if ($context->callerStrictTypes) {
                return JitStringBuiltinArg::lowerZparamStr($context, $args[0], 'mb_detect_encoding', 0, 'string');
            }
            $string = '';
        } else {
            $string = JitStringArg::compileTimeLiteral($args[0]);
            if (null === $string) {
                return null;
            }
        }

        $order = self::compileTimeEncodingList($args, 1);
        if (null === $order) {
            return null;
        }
        if (!self::orderIsSupported($order)) {
            return null;
        }

        $strict = self::compileTimeStrict($args, 2);
        $result = VmMbstring::detectEncoding($string, $order, $strict);
        if (false === $result) {
            return $context->constantFromBool(false);
        }

        return self::materializeString($context, $result);
    }

    /**
     * @param JITVariable[] $args
     *
     * @return list<string>|null
     */
    private static function compileTimeEncodingList(array $args, int $index): ?array
    {
        if (!isset($args[$index])) {
            return MbstringState::detectOrder();
        }
        if (JITVariable::TYPE_NULL === $args[$index]->type || ($args[$index]->isNullConstant ?? false)) {
            return MbstringState::detectOrder();
        }
        if (JITVariable::TYPE_STRING === $args[$index]->type) {
            $lit = $args[$index]->compileTimeString ?? null;
            if (null === $lit) {
                return null;
            }

            return MbstringEncodingRegistry::parseOrderList('mb_detect_encoding', $index, $lit);
        }
        if (self::isArrayArg($args[$index]) && null !== ($args[$index]->compileTimeArray ?? null)) {
            $order = [];
            foreach ($args[$index]->compileTimeArray as $elem) {
                $lit = null;
                if (\is_string($elem)) {
                    $lit = $elem;
                } elseif ($elem instanceof JITVariable) {
                    if (JITVariable::TYPE_STRING !== $elem->type || null === ($elem->compileTimeString ?? null)) {
                        return null;
                    }
                    $lit = $elem->compileTimeString;
                } else {
                    return null;
                }
                $canonical = MbstringEncodingRegistry::resolve($lit);
                if (null === $canonical) {
                    return null;
                }
                $order[] = $canonical;
            }
            MbstringEncodingRegistry::assertNonEmptyOrder('mb_detect_encoding', $index, $order);

            return $order;
        }

        return null;
    }

    /**
     * @param JITVariable[] $args
     */
    private static function runtimeStrictLiteral(array $args, int $index): bool
    {
        if (!isset($args[$index])) {
            return false;
        }
        if (JITVariable::TYPE_NATIVE_BOOL === $args[$index]->type && JITVariable::KIND_VALUE === $args[$index]->kind) {
            $const = $args[$index]->value;
            if ($const instanceof Value && $const->isConstant()) {
                return 0 !== (int) $const->constInt();
            }
        }

        throw new \LogicException(
            'mb_detect_encoding() strict must be a compile-time bool literal in this compiler build'
        );
    }

    /**
     * @param JITVariable[] $args
     */
    private static function compileTimeStrict(array $args, int $index): bool
    {
        if (!isset($args[$index])) {
            return false;
        }
        if (JITVariable::TYPE_NATIVE_BOOL === $args[$index]->type && JITVariable::KIND_VALUE === $args[$index]->kind) {
            $const = $args[$index]->value;
            if ($const instanceof Value && $const->isConstant()) {
                return 0 !== (int) $const->constInt();
            }
        }

        return false;
    }

    /**
     * @param list<string> $order
     */
    private static function assertSupportedOrder(array $order): void
    {
        if (!self::orderIsSupported($order)) {
            throw new \LogicException(
                'mb_detect_encoding() JIT only supports UTF-8, ASCII, ISO-8859-1, and 8BIT encoding lists in this compiler build'
            );
        }
    }

    /**
     * @param list<string> $order
     */
    private static function orderIsSupported(array $order): bool
    {
        foreach ($order as $encoding) {
            if (!\in_array($encoding, ['UTF-8', 'ASCII', 'ISO-8859-1', '8BIT'], true)) {
                return false;
            }
        }

        return true;
    }

    private static function isArrayArg(JITVariable $arg): bool
    {
        return JITVariable::TYPE_HASHTABLE === $arg->type
            || (($arg->type & JITVariable::IS_NATIVE_ARRAY) !== 0)
            || ($arg->compileTimeEmptyArrayLiteral ?? false)
            || null !== ($arg->compileTimeArray ?? null);
    }

    private static function boxStringOrFalse(Context $context, Value $raw): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_detect_encoding_box');
        $i32 = $context->getTypeFromString('int32');
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $isMiss = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
        $missBb = BasicBlockHelper::append($context, 'mb_detect_encoding_miss');
        $hitBb = BasicBlockHelper::append($context, 'mb_detect_encoding_hit');
        $doneBb = BasicBlockHelper::append($context, 'mb_detect_encoding_done');
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
