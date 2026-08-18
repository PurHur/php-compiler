<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for is_uploaded_file() (#2204). */
final class IsUploadedFileVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'is_uploaded_file.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/is_uploaded_file.phpt',
            'is_uploaded_file.phpt'
        );
        yield 'is_uploaded_file_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/is_uploaded_file_null_strict.phpt',
            'is_uploaded_file_null_strict.phpt'
        );
        yield 'named_args_is_uploaded_file.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/named_args_is_uploaded_file.phpt',
            'named_args_is_uploaded_file.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
