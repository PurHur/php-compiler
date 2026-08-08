<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for FILTER_DEFAULT STRIP_/ENCODE_ flags (#29064). */
final class FilterVarDefaultStripEncodeVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'filter_var_default_strip_encode.phpt' => self::parsePHPT(
            __DIR__.'/cases/filter/filter_var_default_strip_encode.phpt',
            'filter_var_default_strip_encode.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
