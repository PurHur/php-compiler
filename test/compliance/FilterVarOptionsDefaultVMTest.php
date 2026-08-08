<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for filter_var() options[default] (#29046). */
final class FilterVarOptionsDefaultVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'filter_var_options_default.phpt' => self::parsePHPT(
            __DIR__.'/cases/filter/filter_var_options_default.phpt',
            'filter_var_options_default.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
