<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: date_date_set/date_time_set + DateTime(Immutable)::setDate/setTime (#30747).
 *
 * @group llvm
 * @group jit
 */
final class DateDateTimeSetJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'date_date_time_set_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/date_date_time_set_jit.phpt',
            'date_date_time_set_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
