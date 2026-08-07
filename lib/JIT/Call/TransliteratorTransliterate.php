<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\intl\JitTransliteratorTransliterate;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * Transliterator::transliterate() — JIT/AOT via CT fold / NestedJIT (#28657).
 *
 * php-src: ext/intl/transliterator/transliterator_methods.c — zim_Transliterator_transliterate
 */
final class TransliteratorTransliterate implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name = 'Transliterator::transliterate';

    /** @var list<string> php-src transliterator.stub.php */
    public array $paramNames = ['string', 'start', 'end'];

    public function call(Context $context, Variable ...$args): Value
    {
        return JitTransliteratorTransliterate::invokeMethod($context, ...$args);
    }
}
