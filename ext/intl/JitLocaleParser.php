<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\JIT\Builtin\LocaleParser;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT helper for locale_get_* BCP-47 parsers + canonicalize (#17072, #20760).
 *
 * Z_PARAM_STR $locale — null TypeError on PROFILE=8.4 (#21078, locale.stub.php).
 */
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
            JitStringBuiltinArg::lowerZparamStr($context, $locale, 'locale_get_primary_language', 0, 'locale')
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
            JitStringBuiltinArg::lowerZparamStr($context, $locale, 'locale_get_region', 0, 'locale')
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
            JitStringBuiltinArg::lowerZparamStr($context, $locale, 'locale_get_script', 0, 'locale')
        );
    }

    public static function canonicalize(Context $context, JITVariable $locale, string $function = 'locale_canonicalize'): Value
    {
        $literal = $locale->compileTimeString ?? JitStringArg::compileTimeLiteral($locale);
        if (null !== $literal) {
            $result = VmLocale::canonicalize($literal);
            if (null === $result) {
                $slot = JitValueBox::alloc($context);
                $context->builder->call(
                    $context->lookupFunction('__value__writeNull'),
                    JitValueBox::pointer($context, $slot)
                );

                return $slot;
            }

            return $context->builder->load(
                $context->constantStringFromString($result)
            );
        }

        return LocaleParser::invokeCanonicalize(
            $context,
            JitStringBuiltinArg::lowerZparamStr($context, $locale, $function, 0, 'locale')
        );
    }

    public static function getDefault(Context $context): Value
    {
        return LocaleParser::invokeDefault($context);
    }
}
