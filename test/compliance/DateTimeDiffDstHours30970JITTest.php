<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: DateTime::diff()/date_diff() DST span uses elapsed hours (#30970).
 *
 * @group llvm
 * @group jit
 */
final class DateTimeDiffDstHours30970JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetime_diff_dst_hours.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/datetime_diff_dst_hours.phpt',
            'datetime_diff_dst_hours.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
