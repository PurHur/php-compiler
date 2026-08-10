<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: DateTime::setTimestamp(null) TypeError under strict_types (#29841).
 *
 * @group llvm
 * @group jit
 */
final class DateTimeSetTimestampNullStrictJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetime_settimestamp_null_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/datetime_settimestamp_null_strict_jit.phpt',
            'datetime_settimestamp_null_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
