<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * IntlDateFormatter::create() — JIT/AOT factory (#27361 / re-#20837).
 *
 * Dispatch via {@see Context::$extensionLowering} so lib/JIT does not import
 * {@code ext\intl} (#36204). php-src: ext/intl/dateformat/dateformat_create.cpp — zim_IntlDateFormatter_create
 */
final class IntlDateFormatterCreate implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name = 'IntlDateFormatter::create';

    /** @var list<string> php-src dateformat.stub.php */
    public array $paramNames = ['locale', 'dateType', 'timeType', 'timezone', 'calendar', 'pattern'];

    /** Static factory — no implicit $this. */
    public int $namedArgsReceiverPrefix = 0;

    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireIntl()->intlDateFormatterCreate($context, ...$args);
    }
}
