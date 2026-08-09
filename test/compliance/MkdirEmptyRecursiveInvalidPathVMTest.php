<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: mkdir("", 0777, true) warns Invalid path (#29359, ext/standard/filestat.c). */
final class MkdirEmptyRecursiveInvalidPathVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'mkdir_empty_recursive_invalid_path.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/mkdir_empty_recursive_invalid_path.phpt',
            'mkdir_empty_recursive_invalid_path.phpt'
        );
    }

    public function setUp(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
    }
}
