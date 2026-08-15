<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: scandir null $sorting_order under strict_types → TypeError (#31244).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class ScandirNullSortingOrder31244VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'scandir_null_sorting_order_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/scandir_null_sorting_order_strict.phpt',
            'scandir_null_sorting_order_strict.phpt'
        );
        yield 'scandir_null_sorting_order_soft_dep.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/scandir_null_sorting_order_soft_dep.phpt',
            'scandir_null_sorting_order_soft_dep.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
