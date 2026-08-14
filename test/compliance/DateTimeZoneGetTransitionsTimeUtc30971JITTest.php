<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: DateTimeZone::getTransitions() time is UTC ISO-8601 (#30971).
 *
 * @group llvm
 * @group jit
 */
final class DateTimeZoneGetTransitionsTimeUtc30971JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetimezone_get_transitions_time_utc.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/datetimezone_get_transitions_time_utc.phpt',
            'datetimezone_get_transitions_time_utc.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
