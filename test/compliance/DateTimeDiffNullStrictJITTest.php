<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: DateTime::diff(null) TypeError Argument #1 ($targetObject) (#29868).
 *
 * @group llvm
 * @group jit
 */
final class DateTimeDiffNullStrictJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetime_diff_null_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/datetime_diff_null_strict_jit.phpt',
            'datetime_diff_null_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
