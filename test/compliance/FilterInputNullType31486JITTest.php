<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: filter_* soft-null $type/$input_type E_DEPRECATED + strict TypeError (#31486).
 */
final class FilterInputNullType31486JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'filter_input_null_type_soft_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/filter/filter_input_null_type_soft_jit.phpt',
            'filter_input_null_type_soft_jit.phpt'
        );
        yield 'filter_input_null_type_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/filter/filter_input_null_type_strict_jit.phpt',
            'filter_input_null_type_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
