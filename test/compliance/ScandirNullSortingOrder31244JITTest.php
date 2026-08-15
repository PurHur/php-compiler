<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: scandir null $sorting_order under strict_types → TypeError (#31244).
 */
final class ScandirNullSortingOrder31244JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'scandir_null_sorting_order_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/scandir_null_sorting_order_strict_jit.phpt',
            'scandir_null_sorting_order_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
