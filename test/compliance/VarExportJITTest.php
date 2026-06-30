<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * @group llvm
 */
/** JIT compliance for var_export() (#5190). */
final class VarExportJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'var_export_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/var_export_jit.phpt',
            'var_export_jit.phpt'
        );
        yield 'var_export_mangled_array_keys_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/var_export_mangled_array_keys_jit.phpt',
            'var_export_mangled_array_keys_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
