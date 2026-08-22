<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbEregRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for mb_ereg() / mb_eregi() / mb_ereg_match() via MbEregJitHelper (#33811).
 *
 * Compile-time fold stays in {@see JitMbEregSearch}; runtime uses NestedJIT helper calls
 * (peer {@see JitMbStrSplit} / #26870). &$regs write deferred — 3-arg FUNCCALL IR (#33811).
 *
 * php-src: ext/mbstring/php_mbregex.c
 */
final class JitMbEreg
{
    /**
     * mb_ereg() / mb_eregi() — runtime pattern/string (2-arg path; &$regs follow-up).
     *
     * @param list<JITVariable> $args
     */
    public static function invokeMatch(Context $context, array $args, bool $caseInsensitive): Value
    {
        $pattern = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[0],
            $caseInsensitive ? 'mb_eregi' : 'mb_ereg',
            0,
            'pattern'
        );
        $string = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[1],
            $caseInsensitive ? 'mb_eregi' : 'mb_ereg',
            1,
            'string'
        );

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbEregRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }

        $matchFn = $caseInsensitive
            ? MbEregRuntime::eregiMatchHelper($context)
            : MbEregRuntime::eregMatchHelper($context);
        $matchedRaw = JitNestedHelperCoerce::callHelper(
            $context,
            $matchFn,
            [$pattern, $string]
        );

        return self::boolBoxFromI64($context, $matchedRaw);
    }

    /**
     * mb_ereg_match() — 2–3 args, anchored bool.
     *
     * @param list<JITVariable> $args
     */
    public static function invokeMatchAnchored(Context $context, array $args): Value
    {
        $pattern = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[0],
            'mb_ereg_match',
            0,
            'pattern'
        );
        $string = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[1],
            'mb_ereg_match',
            1,
            'string'
        );
        $optionsPtr = $context->builder->load($context->constantStringFromString(''));
        $hasOptions = $context->getTypeFromString('int64')->constInt(0, false);
        if (\count($args) >= 3 && JITVariable::TYPE_NULL !== $args[2]->type && !$args[2]->isNullConstant) {
            $optionsPtr = JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $args[2],
                'mb_ereg_match',
                2,
                'options'
            );
            $hasOptions = $context->getTypeFromString('int64')->constInt(1, false);
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbEregRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }

        $matchedRaw = JitNestedHelperCoerce::callHelper(
            $context,
            MbEregRuntime::matchAnchoredHelper($context),
            [$pattern, $string, $optionsPtr, $hasOptions]
        );

        return self::boolBoxFromI64($context, $matchedRaw);
    }

    private static function boolBoxFromI64(Context $context, Value $matchedRaw): Value
    {
        $slot = JitValueBox::alloc($context);
        $i64 = $context->getTypeFromString('int64');
        $matchedI1 = $context->builder->icmp(
            \PHPLLVM\Builder::INT_NE,
            $matchedRaw,
            $i64->constInt(0, false)
        );
        JitValueBox::writeBool($context, $slot, $matchedI1);

        return JitValueBox::pointer($context, $slot);
    }
}
