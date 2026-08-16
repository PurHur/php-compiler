<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: array_fill / array_reverse Reflection + Zend named params (#23305).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class ArrayFillReverseNamed23305VMTest extends BaseTest
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
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
