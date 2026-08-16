<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: print_r/var_export(..., null) $return under strict_types → TypeError (#31337). */
final class PrintRVarExportNullReturnStrict31337JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'print_r_var_export_null_return_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/print_r_var_export_null_return_strict_jit.phpt',
            'print_r_var_export_null_return_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
