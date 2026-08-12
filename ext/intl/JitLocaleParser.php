<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\JIT\Builtin\LocaleParser;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitNativeString;
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

    /**
     * Locale::acceptFromHttp() / locale_accept_from_http() (#28656 / #20036).
     *
     * Compile-time: `__string__*` or `__value__*` false. Runtime: NestedJIT `__string__*`
     * (empty when negotiation fails — same empty-on-fail shape as canonicalize null).
     *
     * Z_PARAM_STR $header — null TypeError under caller strict_types (#29914). Do not fold
     * typed/constant null to "" before the strict guard (that path returned false).
     */
    public static function acceptFromHttp(Context $context, JITVariable $header, string $function = 'locale_accept_from_http'): Value
    {
        $nullConst = JITVariable::TYPE_NULL === $header->type || ($header->isNullConstant ?? false);
        if ($nullConst && $context->callerStrictTypes) {
            JitNativeString::ensureInsertBlock($context);
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                \sprintf('%s(): Argument #1 ($header) must be of type string, null given', $function)
            );
            $slot = JitValueBox::alloc($context);
            JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

            return $slot;
        }

        // Only fold real string literals — null may stash compileTimeString '' (#29914).
        $literal = null;
        if (!$nullConst && JITVariable::TYPE_STRING === $header->type) {
            $literal = $header->compileTimeString ?? JitStringArg::compileTimeLiteral($header);
        }
        if (null !== $literal) {
            $result = VmLocale::acceptFromHttp($literal);
            if (false === $result) {
                $slot = JitValueBox::alloc($context);
                JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

                return $slot;
            }

            return $context->builder->load(
                $context->constantStringFromString($result)
            );
        }

        return LocaleParser::invokeAcceptFromHttp(
            $context,
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $header, $function, 0, 'header')
        );
    }

    public static function getDefault(Context $context): Value
    {
        return LocaleParser::invokeDefault($context);
    }
}
