<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * NumberFormatter::format() — JIT/AOT DECIMAL via NumberFormatterFormatJitHelper (#28648).
 *
 * Dispatch via {@see Context::$extensionLowering} so lib/JIT does not import
 * {@code ext\intl} (#36204). php-src: ext/intl/formatter/formatter_main.c — zim_NumberFormatter_format
 */
final class NumberFormatterFormat implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireIntl()->numberFormatterFormat($context, ...$args);
    }
}
