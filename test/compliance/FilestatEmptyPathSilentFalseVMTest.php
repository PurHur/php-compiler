<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: filestat empty path returns false without E_WARNING (#29343, ext/standard/filestat.c). */
final class FilestatEmptyPathSilentFalseVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'filestat_empty_path_silent_false.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/filestat_empty_path_silent_false.phpt',
            'filestat_empty_path_silent_false.phpt'
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
