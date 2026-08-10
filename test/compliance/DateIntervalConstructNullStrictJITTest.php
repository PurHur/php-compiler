<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: DateInterval::__construct(null) TypeError under strict_types (#29828).
 *
 * @group llvm
 * @group jit
 */
final class DateIntervalConstructNullStrictJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dateinterval_construct_null_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/dateinterval_construct_null_strict_jit.phpt',
            'dateinterval_construct_null_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
