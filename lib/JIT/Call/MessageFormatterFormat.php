<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * MessageFormatter::format() — JIT/AOT via MessageFormatterFormatJitHelper (#28655).
 *
 * Dispatch via {@see Context::$extensionLowering} so lib/JIT does not import
 * {@code ext\intl} (#36204). php-src: ext/intl/msgformat/msgformat_format.c — zim_MessageFormatter_format
 */
final class MessageFormatterFormat implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireIntl()->messageFormatterFormat($context, ...$args);
    }
}
