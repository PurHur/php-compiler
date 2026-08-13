<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: date_timezone_get/set + DateTime(Immutable)::getTimezone (#30746).
 *
 * @group llvm
 * @group jit
 */
final class DateTimezoneGetSetJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'date_timezone_get_set_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/date_timezone_get_set_jit.phpt',
            'date_timezone_get_set_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
