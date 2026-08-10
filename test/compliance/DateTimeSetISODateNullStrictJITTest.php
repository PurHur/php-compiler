<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: DateTime::setISODate(null) TypeError under strict_types (#29842).
 *
 * @group llvm
 * @group jit
 */
final class DateTimeSetISODateNullStrictJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetime_setisodate_null_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/datetime_setisodate_null_strict_jit.phpt',
            'datetime_setisodate_null_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
