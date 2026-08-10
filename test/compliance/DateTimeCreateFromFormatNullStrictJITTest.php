<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: DateTime(Immutable)::createFromFormat(null) TypeError under strict_types (#29830).
 *
 * @group llvm
 * @group jit
 */
final class DateTimeCreateFromFormatNullStrictJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetime_createfromformat_null_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/datetime_createfromformat_null_strict_jit.phpt',
            'datetime_createfromformat_null_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
