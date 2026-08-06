<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * @group llvm
 */
/** JIT compliance for array_is_list() (#2211). */
final class ArrayIsListJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_is_list_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_is_list_jit.phpt',
            'array_is_list_jit.phpt'
        );
        yield 'array_is_list_type_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_is_list_type_jit.phpt',
            'array_is_list_type_jit.phpt'
        );
        yield 'array_is_list_enum_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_is_list_enum_jit.phpt',
            'array_is_list_enum_jit.phpt'
        );
        yield 'array_is_list_enum_operand_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_is_list_enum_operand_jit.phpt',
            'array_is_list_enum_operand_jit.phpt'
        );
        yield 'array_is_list_unset_restore_28051.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_is_list_unset_restore_28051.phpt',
            'array_is_list_unset_restore_28051.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
