<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for version_compare/extension_loaded/get_loaded_extensions (#3204). */
final class VersionCompareVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'version_compare.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/version_compare.phpt',
            'version_compare.phpt'
        );
        yield 'version_compare_partial.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/version_compare_partial.phpt',
            'version_compare_partial.phpt'
        );
        yield 'version_compare_enum_typeerror.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/version_compare_enum_typeerror.phpt',
            'version_compare_enum_typeerror.phpt'
        );
        yield 'get_loaded_extensions_null.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/get_loaded_extensions_null.phpt',
            'get_loaded_extensions_null.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
