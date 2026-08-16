<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: print_r/var_export(..., null) $return under strict_types → TypeError (#31337).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class PrintRVarExportNullReturnStrict31337VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'print_r_var_export_null_return_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/print_r_var_export_null_return_strict.phpt',
            'print_r_var_export_null_return_strict.phpt'
        );
        yield 'print_r_var_export_null_return_soft_dep.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/print_r_var_export_null_return_soft_dep.phpt',
            'print_r_var_export_null_return_soft_dep.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
