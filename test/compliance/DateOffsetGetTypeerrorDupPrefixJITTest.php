<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: date_offset_get(null) TypeError single Argument #1 prefix (#29864).
 *
 * @group llvm
 * @group jit
 */
final class DateOffsetGetTypeerrorDupPrefixJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'date_offset_get_typeerror_dup_prefix_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/date_offset_get_typeerror_dup_prefix_jit.phpt',
            'date_offset_get_typeerror_dup_prefix_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
