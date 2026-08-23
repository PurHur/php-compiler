<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: array_rand() Reflection return and $num default match Zend stub (#25499).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class ArrayRandReflection25499VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_rand_reflection_25499.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_rand_reflection_25499.phpt',
            'array_rand_reflection_25499.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
