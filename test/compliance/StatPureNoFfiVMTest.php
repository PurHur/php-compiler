<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for stat()/lstat() pure path without libc stat FFI (#8903). */
final class StatPureNoFfiVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'stat_pure_no_ffi.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/stat_pure_no_ffi.phpt',
            'stat_pure_no_ffi.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
