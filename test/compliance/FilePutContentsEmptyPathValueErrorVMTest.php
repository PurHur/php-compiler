<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: file_put_contents('') ValueError "Path must not be empty" (#29294, php-src file.c). */
final class FilePutContentsEmptyPathValueErrorVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'file_put_contents_empty_path_valueerror.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/file_put_contents_empty_path_valueerror.phpt',
            'file_put_contents_empty_path_valueerror.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
