<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: DateInterval::__construct(null) TypeError under strict_types (#29828). */
final class DateIntervalConstructNullStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dateinterval_construct_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/dateinterval_construct_null_strict.phpt',
            'dateinterval_construct_null_strict.phpt'
        );
        yield 'dateinterval_construct_null_strict_forward84.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/dateinterval_construct_null_strict_forward84.phpt',
            'dateinterval_construct_null_strict_forward84.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
