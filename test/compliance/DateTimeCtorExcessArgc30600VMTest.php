<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: DateTime/DateTimeImmutable::__construct excess argc → ArgumentCountError (#30600). */
final class DateTimeCtorExcessArgc30600VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_datetime_ctor_30600.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_datetime_ctor_30600.phpt',
            'excess_argc_datetime_ctor_30600.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
