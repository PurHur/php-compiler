<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: createFromTimestamp(NAN|INF) is DateRangeError with finite-range wording (#31119).
 *
 * @group llvm
 * @group jit
 */
final class DateTimeCreateFromTimestampNan31119JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetime_create_from_timestamp_nan_84_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/datetime_create_from_timestamp_nan_84_jit.phpt',
            'datetime_create_from_timestamp_nan_84_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
    }

    public function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
    }
}
