<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT compliance for filter_var() options[default] (#29046). */
final class FilterVarOptionsDefaultJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'filter_var_options_default_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/filter/filter_var_options_default_jit.phpt',
            'filter_var_options_default_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
