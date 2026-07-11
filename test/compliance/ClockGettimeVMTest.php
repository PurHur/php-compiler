<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for clock_gettime() / ClockInterface (#11624). */
final class ClockGettimeVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        if (!CompilerVersion::supportsClockGettime()) {
            return;
        }
        yield 'clock_gettime.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/clock_gettime.phpt',
            'clock_gettime.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
