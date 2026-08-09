<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: chroot("") warns No such file or directory (errno 2) (#29360, ext/standard/dir.c). */
final class ChrootEmptyPathWarningVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'chroot_empty_path_warning.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/chroot_empty_path_warning.phpt',
            'chroot_empty_path_warning.phpt'
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
