<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\LocaleFilterMatchesRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT helper for locale_filter_matches() (#32119).
 *
 * Compile-time: fold prefix filter through {@see VmLocale::filterMatches()}.
 * Runtime: NestedJIT {@see LocaleFilterMatchesJitHelper::filterMatchesArgv()}.
 * php-src: ext/intl/locale/locale_methods.c
 */
final class JitLocaleFilterMatches
{
    public static function filterMatches(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'locale_filter_matches() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'locale_filter_matches() expects at most 3 arguments, %d given',
                $argc
            ));
        }

        $folded = self::tryFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        $languageTag = JitStringBuiltinArg::lowerZparamStr(
            $context,
            $args[0],
            'locale_filter_matches',
            0,
            'languageTag'
        );
        BasicBlockHelper::ensureOpenInsertBlock($context, 'locale_filter_matches_langtag_cont');
        $locale = JitStringBuiltinArg::lowerZparamStr(
            $context,
            $args[1],
            'locale_filter_matches',
            1,
            'locale'
        );
        BasicBlockHelper::ensureOpenInsertBlock($context, 'locale_filter_matches_locale_cont');

        $i64 = $context->getTypeFromString('int64');
        $canonicalize = $i64->constInt(0, false);
        if ($argc >= 3) {
            $canonicalize = $context->builder->zExt(
                JitBoolArg::lowerCoerceZParamBool(
                    $context,
                    $args[2],
                    'locale_filter_matches',
                    'canonicalize',
                    3
                ),
                $i64
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'locale_filter_matches_canon_cont');
        }

        return LocaleFilterMatchesRuntime::invoke($context, $languageTag, $locale, $canonicalize);
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function tryFold(Context $context, array $args): ?Value
    {
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)
            || JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)
        ) {
            return null;
        }
        $languageTag = $args[0]->compileTimeString ?? JitStringArg::compileTimeLiteral($args[0]);
        $locale = $args[1]->compileTimeString ?? JitStringArg::compileTimeLiteral($args[1]);
        if (null === $languageTag || null === $locale) {
            return null;
        }
        $canonicalize = false;
        if (isset($args[2])) {
            $canonLit = self::compileTimeBool($args[2]);
            if (null === $canonLit) {
                return null;
            }
            $canonicalize = $canonLit;
        }

        $result = VmLocale::filterMatches($languageTag, $locale, $canonicalize);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'locale_filter_matches_fold');

        return $context->constantFromBool($result);
    }

    private static function compileTimeBool(JITVariable $arg): ?bool
    {
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return false;
        }
        if (null !== $arg->compileTimeLong) {
            return 0 !== $arg->compileTimeLong;
        }
        $literal = JitStringArg::compileTimeLiteral($arg);
        if (null !== $literal) {
            return '' !== $literal && '0' !== $literal;
        }
        if (JITVariable::TYPE_NATIVE_BOOL === $arg->type) {
            $value = $arg->value;
            if (method_exists($value, 'isConstant') && $value->isConstant()
                && method_exists($value, 'getConstantValue')
            ) {
                return 0 !== (int) $value->getConstantValue();
            }
        }

        return null;
    }
}
