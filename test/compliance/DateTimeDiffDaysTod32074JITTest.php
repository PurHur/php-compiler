<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: DateTime::diff $days decrements when later TOD is earlier (#32074).
 *
 * @group llvm
 * @group jit
 */
final class DateTimeDiffDaysTod32074JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetime_diff_days_tod.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/datetime_diff_days_tod.phpt',
            'datetime_diff_days_tod.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
