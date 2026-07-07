<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\JIT\Builtin\LocaleParser;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM JIT helper for locale_get_* BCP-47 parsers (#17072). */
final class JitLocaleParser
{
    public static function primaryLanguage(Context $context, JITVariable $locale): Value
    {
        $literal = $locale->compileTimeString ?? JitStringArg::compileTimeLiteral($locale);
        if (null !== $literal) {
            return $context->builder->load(
                $context->constantStringFromString(VmLocale::getPrimaryLanguage($literal))
            );
        }

        return LocaleParser::invokePrimaryLanguage(
            $context,
            JitStringBuiltinArg::lower($context, $locale, 'locale_get_primary_language', 0, 'locale')
        );
    }

    public static function region(Context $context, JITVariable $locale): Value
    {
        $literal = $locale->compileTimeString ?? JitStringArg::compileTimeLiteral($locale);
        if (null !== $literal) {
            return $context->builder->load(
                $context->constantStringFromString(VmLocale::getRegion($literal))
            );
        }

        return LocaleParser::invokeRegion(
            $context,
            JitStringBuiltinArg::lower($context, $locale, 'locale_get_region', 0, 'locale')
        );
    }

    public static function script(Context $context, JITVariable $locale): Value
    {
        $literal = $locale->compileTimeString ?? JitStringArg::compileTimeLiteral($locale);
        if (null !== $literal) {
            return $context->builder->load(
                $context->constantStringFromString(VmLocale::getScript($literal))
            );
        }

        return LocaleParser::invokeScript(
            $context,
            JitStringBuiltinArg::lower($context, $locale, 'locale_get_script', 0, 'locale')
        );
    }
}
