<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: DateInterval::__construct excess argc → ArgumentCountError (#30601). */
final class DateIntervalCtorExcessArgc30601JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_dateinterval_ctor_30601_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_dateinterval_ctor_30601_jit.phpt',
            'excess_argc_dateinterval_ctor_30601_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
