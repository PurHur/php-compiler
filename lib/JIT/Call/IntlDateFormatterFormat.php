<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * IntlDateFormatter::format() — JIT/AOT via IntlDateFormatterFormatJitHelper (#27361).
 *
 * Dispatch via {@see Context::$extensionLowering} so lib/JIT does not import
 * {@code ext\intl} (#36204). php-src: ext/intl/dateformat/dateformat_format.c — zim_IntlDateFormatter_format
 */
final class IntlDateFormatterFormat implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireIntl()->intlDateFormatterFormat($context, ...$args);
    }
}
