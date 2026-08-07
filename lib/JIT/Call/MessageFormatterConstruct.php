<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\intl\JitMessageFormatterConstruct;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * MessageFormatter::__construct() — persist locale/pattern for AOT format (#28655).
 *
 * php-src: ext/intl/msgformat/msgformat_class.c — zim_MessageFormatter___construct
 */
final class MessageFormatterConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitMessageFormatterConstruct::invoke($context, ...$args);
    }
}
