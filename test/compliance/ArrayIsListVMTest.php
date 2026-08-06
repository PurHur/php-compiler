<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for array_is_list() (#2211). */
final class ArrayIsListVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_is_list.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_is_list.phpt',
            'array_is_list.phpt'
        );
        yield 'array_is_list_type.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_is_list_type.phpt',
            'array_is_list_type.phpt'
        );
        yield 'array_is_list_enum_operand.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_is_list_enum_operand.phpt',
            'array_is_list_enum_operand.phpt'
        );
        yield 'array_is_list_unset_restore_28051.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_is_list_unset_restore_28051.phpt',
            'array_is_list_unset_restore_28051.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
