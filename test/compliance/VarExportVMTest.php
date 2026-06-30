<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for var_export() (#5190). */
final class VarExportVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'var_export.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/var_export.phpt',
            'var_export.phpt'
        );
        yield 'var_export_mangled_array_keys.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/var_export_mangled_array_keys.phpt',
            'var_export_mangled_array_keys.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
