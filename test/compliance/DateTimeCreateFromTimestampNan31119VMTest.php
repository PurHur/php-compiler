<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: createFromTimestamp(NAN|INF) is DateRangeError with finite-range wording (#31119).
 *
 * PROFILE=8.4 via phpt --ENV-- (and setUp); always registered so default-profile phpunit still runs it.
 */
final class DateTimeCreateFromTimestampNan31119VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetime_create_from_timestamp_nan_84.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/datetime_create_from_timestamp_nan_84.phpt',
            'datetime_create_from_timestamp_nan_84.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
    }

    public function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
    }
}
