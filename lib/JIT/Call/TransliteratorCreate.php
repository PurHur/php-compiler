<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\intl\JitTransliteratorCreate;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * Transliterator::create() — JIT/AOT factory + CT ID stash (#28657).
 *
 * php-src: ext/intl/transliterator/transliterator_methods.c — zim_Transliterator_create
 */
final class TransliteratorCreate implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name = 'Transliterator::create';

    /** @var list<string> php-src transliterator.stub.php */
    public array $paramNames = ['id', 'direction'];

    /** Static factory — no implicit $this. */
    public int $namedArgsReceiverPrefix = 0;

    public function call(Context $context, Variable ...$args): Value
    {
        return JitTransliteratorCreate::invoke($context, ...$args);
    }
}
