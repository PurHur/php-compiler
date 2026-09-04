<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\IntlExtensionHooks;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * intl surfaces for lib/JIT Call Collator/Normalizer/formatters (#36204).
 *
 * php-src: ext/intl/{collator,normalizer,formatter,dateformat,msgformat,transliterator}/
 * Registered from {@see Module::jitInit} so Call files do not import ext/intl.
 */
final class JitIntlExtensionHooksFacade implements IntlExtensionHooks
{
    public function collatorCompare(Context $context, JITVariable ...$args): Value
    {
        return JitCollatorCompare::invokeMethod($context, ...$args);
    }

    public function messageFormatterConstruct(Context $context, JITVariable ...$args): Value
    {
        return JitMessageFormatterConstruct::invoke($context, ...$args);
    }

    public function messageFormatterFormat(Context $context, JITVariable ...$args): Value
    {
        return JitMessageFormatterFormat::invokeMethod($context, ...$args);
    }

    public function normalizerNormalize(Context $context, JITVariable ...$args): Value
    {
        return JitNormalizerNormalize::invokeMethod($context, ...$args);
    }

    public function numberFormatterCreate(Context $context, JITVariable ...$args): Value
    {
        return JitNumberFormatterCreate::invoke($context, ...$args);
    }

    public function numberFormatterFormat(Context $context, JITVariable ...$args): Value
    {
        return JitNumberFormatterFormat::invokeMethod($context, ...$args);
    }

    public function intlDateFormatterCreate(Context $context, JITVariable ...$args): Value
    {
        return JitIntlDateFormatterCreate::invoke($context, ...$args);
    }

    public function intlDateFormatterFormat(Context $context, JITVariable ...$args): Value
    {
        return JitIntlDateFormatterFormat::invokeMethod($context, ...$args);
    }

    public function transliteratorCreate(Context $context, JITVariable ...$args): Value
    {
        return JitTransliteratorCreate::invoke($context, ...$args);
    }

    public function transliteratorTransliterate(Context $context, JITVariable ...$args): Value
    {
        return JitTransliteratorTransliterate::invokeMethod($context, ...$args);
    }
}
