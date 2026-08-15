<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: filter_var null $options under strict_types → TypeError (#31209).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class FilterVarNullOptions31209VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'filter_var_null_options_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/filter/filter_var_null_options_strict.phpt',
            'filter_var_null_options_strict.phpt'
        );
        yield 'filter_var_null_options_soft_dep.phpt' => self::parsePHPT(
            __DIR__.'/cases/filter/filter_var_null_options_soft_dep.phpt',
            'filter_var_null_options_soft_dep.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
