<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for php_uname() pure path without libc FFI (#8904). */
final class PhpUnameNoFfiVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'php_uname_no_ffi.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/php_uname_no_ffi.phpt',
            'php_uname_no_ffi.phpt'
        );
        yield 'php_uname_invalid_mode.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/php_uname_invalid_mode.phpt',
            'php_uname_invalid_mode.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
