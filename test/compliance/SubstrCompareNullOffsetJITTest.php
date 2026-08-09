<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * @group llvm
 */
/** JIT: substr_compare(null $offset) soft-null DEP+coerce (#29504, php-src string.c). */
final class SubstrCompareNullOffsetJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'substr_compare_null_offset_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/substr_compare_null_offset_jit.phpt',
            'substr_compare_null_offset_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
