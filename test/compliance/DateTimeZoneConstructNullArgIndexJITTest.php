<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: DateTimeZone::__construct(null) TypeError Argument #1 (#29827).
 *
 * @group llvm
 * @group jit
 */
final class DateTimeZoneConstructNullArgIndexJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetimezone_construct_null_argindex_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/datetimezone_construct_null_argindex_jit.phpt',
            'datetimezone_construct_null_argindex_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
