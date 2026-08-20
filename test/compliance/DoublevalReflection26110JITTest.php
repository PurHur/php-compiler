<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: doubleval Reflection mixed $value): float + named value (#26110).
 */
final class DoublevalReflection26110JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'doubleval_reflection_26110.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/doubleval_reflection_26110.phpt',
            'doubleval_reflection_26110.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
