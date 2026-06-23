<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for array_udiff/uintersect/diff_u* family (#5644). */
final class ArrayUdiffVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_udiff_family.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_udiff_family.phpt',
            'array_udiff_family.phpt'
        );
        yield 'array_intersect_uassoc.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_intersect_uassoc.phpt',
            'array_intersect_uassoc.phpt'
        );
        yield 'array_udiff_null_callback.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_udiff_null_callback.phpt',
            'array_udiff_null_callback.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
