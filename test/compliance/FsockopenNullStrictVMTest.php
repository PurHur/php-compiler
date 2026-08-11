<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: fsockopen(null) TypeError under strict_types (#30313). */
final class FsockopenNullStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'fsockopen_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/fsockopen_null_strict.phpt',
            'fsockopen_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
