<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: fscanf(null) $format TypeError under strict_types (#30236). */
final class FscanfNullFormatStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'fscanf_null_format_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/fscanf_null_format_strict.phpt',
            'fscanf_null_format_strict.phpt'
        );
        yield 'fscanf_null_format_nonstrict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/fscanf_null_format_nonstrict.phpt',
            'fscanf_null_format_nonstrict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
