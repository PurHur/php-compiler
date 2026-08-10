<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: DateTime(Immutable)::modify(null) TypeError under strict_types (#29818, ext/date/php_date.c).
 *
 * @group llvm
 * @group jit
 */
final class DateTimeModifyNullStrictJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetime_modify_null_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/datetime_modify_null_strict_jit.phpt',
            'datetime_modify_null_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
