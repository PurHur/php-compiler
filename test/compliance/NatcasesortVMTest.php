<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for natcasesort(). */
final class NatcasesortVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'natcasesort.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/natcasesort.phpt',
            'natcasesort.phpt'
        );
        yield 'natcasesort_null_elements.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/natcasesort_null_elements.phpt',
            'natcasesort_null_elements.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
