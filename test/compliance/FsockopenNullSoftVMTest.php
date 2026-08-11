<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: fsockopen(null) soft Deprecated+Warning+false (#30313). */
final class FsockopenNullSoftVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'fsockopen_null_soft.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/fsockopen_null_soft.phpt',
            'fsockopen_null_soft.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
