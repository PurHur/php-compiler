<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: DateTime/DateTimeImmutable::__construct excess argc → ArgumentCountError (#30600). */
final class DateTimeCtorExcessArgc30600JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_datetime_ctor_30600_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_datetime_ctor_30600_jit.phpt',
            'excess_argc_datetime_ctor_30600_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
