<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * MessageFormatter::__construct() — persist locale/pattern for AOT format (#28655).
 *
 * Dispatch via {@see Context::$extensionLowering} so lib/JIT does not import
 * {@code ext\intl} (#36204). php-src: ext/intl/msgformat/msgformat_class.c — zim_MessageFormatter___construct
 */
final class MessageFormatterConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireIntl()->messageFormatterConstruct($context, ...$args);
    }
}
