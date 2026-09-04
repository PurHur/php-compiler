<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value;

/**
 * intl extension surfaces needed by lib/JIT Call (#36204).
 *
 * Implemented in {@code ext/intl/JitIntlExtensionHooksFacade.php}; Call
 * Collator/Normalizer/NumberFormatter/IntlDateFormatter/MessageFormatter/
 * Transliterator files must not import {@code ext\intl}.
 */
interface IntlExtensionHooks
{
    public function collatorCompare(Context $context, Variable ...$args): Value;

    public function messageFormatterConstruct(Context $context, Variable ...$args): Value;

    public function messageFormatterFormat(Context $context, Variable ...$args): Value;

    public function normalizerNormalize(Context $context, Variable ...$args): Value;

    public function numberFormatterCreate(Context $context, Variable ...$args): Value;

    public function numberFormatterFormat(Context $context, Variable ...$args): Value;

    public function intlDateFormatterCreate(Context $context, Variable ...$args): Value;

    public function intlDateFormatterFormat(Context $context, Variable ...$args): Value;

    public function transliteratorCreate(Context $context, Variable ...$args): Value;

    public function transliteratorTransliterate(Context $context, Variable ...$args): Value;
}
