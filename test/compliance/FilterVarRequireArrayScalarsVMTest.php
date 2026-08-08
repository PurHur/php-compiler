<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for filter_var() FILTER_REQUIRE_ARRAY list elements (#29047). */
final class FilterVarRequireArrayScalarsVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'filter_var_require_array_scalars.phpt' => self::parsePHPT(
            __DIR__.'/cases/filter/filter_var_require_array_scalars.phpt',
            'filter_var_require_array_scalars.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
