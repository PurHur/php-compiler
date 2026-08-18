<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\LocaleGetDisplayNameRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT helper for locale_get_display_name() (#32120).
 *
 * Compile-time: fold through {@see VmLocale::getDisplayName()} (`__string__*` or boxed false).
 * Runtime: NestedJIT {@see LocaleGetDisplayNameJitHelper::getDisplayNameArgv()} (empty on false,
 * peer {@see JitLocaleParser::acceptFromHttp()}).
 * php-src: ext/intl/locale/locale_methods.c
 */
final class JitLocaleGetDisplayName
{
    public static function getDisplayName(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1) {
            throw new \ArgumentCountError(\sprintf(
                'locale_get_display_name() expects at least 1 argument, %d given',
                $argc
            ));
        }
        if ($argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'locale_get_display_name() expects at most 2 arguments, %d given',
                $argc
            ));
        }

        $folded = self::tryFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        $locale = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[0],
            'locale_get_display_name',
            0,
            'locale'
        );
        BasicBlockHelper::ensureOpenInsertBlock($context, 'locale_get_display_name_locale_cont');

        $i64 = $context->getTypeFromString('int64');
        $display = $context->builder->load($context->constantStringFromString(''));
        $hasDisplay = $i64->constInt(0, false);
        if ($argc >= 2 && JITVariable::TYPE_NULL !== $args[1]->type && !($args[1]->isNullConstant ?? false)) {
            $display = JitStringBuiltinArg::lowerZparamStr(
                $context,
                $args[1],
                'locale_get_display_name',
                1,
                'displayLocale'
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'locale_get_display_name_display_cont');
            $hasDisplay = $i64->constInt(1, false);
        }

        return LocaleGetDisplayNameRuntime::invoke($context, $locale, $display, $hasDisplay);
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function tryFold(Context $context, array $args): ?Value
    {
        $locale = $args[0]->compileTimeString ?? JitStringArg::compileTimeLiteral($args[0]);
        if (null === $locale) {
            return null;
        }
        $displayLocale = null;
        if (isset($args[1]) && JITVariable::TYPE_NULL !== $args[1]->type && !($args[1]->isNullConstant ?? false)) {
            $displayLocale = $args[1]->compileTimeString ?? JitStringArg::compileTimeLiteral($args[1]);
            if (null === $displayLocale) {
                return null;
            }
        }

        $result = VmLocale::getDisplayName($locale, $displayLocale);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'locale_get_display_name_fold');
        if (false === $result) {
            $slot = JitValueBox::alloc($context);
            JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

            return $slot;
        }

        return $context->builder->load($context->constantStringFromString($result));
    }
}
