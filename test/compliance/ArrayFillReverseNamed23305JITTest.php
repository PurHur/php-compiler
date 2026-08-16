<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: array_fill / array_reverse Reflection + Zend named params (#23305).
 */
final class ArrayFillReverseNamed23305JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_fill_reverse_named_23305.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_fill_reverse_named_23305.phpt',
            'array_fill_reverse_named_23305.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
