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
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
