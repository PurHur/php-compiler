<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: date_timestamp_get/set + DateTime(Immutable) timestamp accessors (#30745).
 *
 * @group llvm
 * @group jit
 */
final class DateTimestampGetSetJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'date_timestamp_get_set_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/date_timestamp_get_set_jit.phpt',
            'date_timestamp_get_set_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
