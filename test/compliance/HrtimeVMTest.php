<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for hrtime() (#3195). */
final class HrtimeVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'hrtime.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/hrtime.phpt',
            'hrtime.phpt'
        );
        yield 'hrtime_4583.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/hrtime_4583.phpt',
            'hrtime_4583.phpt'
        );
        yield 'hrtime_jit_vm.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/hrtime_jit_vm.phpt',
            'hrtime_jit_vm.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
