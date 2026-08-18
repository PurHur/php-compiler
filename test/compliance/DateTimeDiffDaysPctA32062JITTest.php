<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: DateTime::diff DateInterval::$days / format('%a') calendar days across Jan/Feb (#32062).
 *
 * @group llvm
 * @group jit
 */
final class DateTimeDiffDaysPctA32062JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetime_diff_days_pct_a.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/datetime_diff_days_pct_a.phpt',
            'datetime_diff_days_pct_a.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
