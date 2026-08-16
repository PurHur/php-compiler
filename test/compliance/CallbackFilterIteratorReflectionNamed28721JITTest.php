<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** Focused JIT PHPT for CallbackFilterIterator::__construct Reflection/named args (#28721). */
final class CallbackFilterIteratorReflectionNamed28721JITTest extends BaseTest
{
    protected string $BIN = __DIR__.'/../../bin/jit.php';

    public static function providePHPTests(): \Generator
    {
        $path = __DIR__.'/cases/stdlib/callbackfilteriterator_reflection_named_28721.phpt';
        [$name, $code, $sections] = self::parsePHPT($path, 'callbackfilteriterator_reflection_named_28721');
        yield $name => [$name, $code, $sections];
    }
}
