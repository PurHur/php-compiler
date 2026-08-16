<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** Focused JIT PHPT for CallbackFilterIterator null-callback ctor TypeError (#31508). */
final class CallbackFilterIteratorNullCallback31508JITTest extends BaseTest
{
    protected string $BIN = __DIR__.'/../../bin/jit.php';

    public static function providePHPTests(): \Generator
    {
        $path = __DIR__.'/cases/stdlib/callbackfilteriterator_null_callback_typeerror_31508.phpt';
        [$name, $code, $sections] = self::parsePHPT(
            $path,
            'callbackfilteriterator_null_callback_typeerror_31508'
        );
        yield $name => [$name, $code, $sections];
    }
}
