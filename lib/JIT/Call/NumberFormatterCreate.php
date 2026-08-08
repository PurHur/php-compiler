<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\intl\JitNumberFormatterCreate;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * NumberFormatter::create() — JIT/AOT factory (#27385 / re-#20754).
 *
 * php-src: ext/intl/formatter/formatter_main.c — zim_NumberFormatter_create
 */
final class NumberFormatterCreate implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name = 'NumberFormatter::create';

    /** @var list<string> php-src formatter.stub.php */
    public array $paramNames = ['locale', 'style', 'pattern'];

    /** Static factory — no implicit $this. */
    public int $namedArgsReceiverPrefix = 0;

    public function call(Context $context, Variable ...$args): Value
    {
        return JitNumberFormatterCreate::invoke($context, ...$args);
    }
}
