<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: substr_replace(null $offset) under strict_types TypeError (#31359). */
final class SubstrReplaceNullOffsetStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'substr_replace_null_offset_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/substr_replace_null_offset_strict.phpt',
            'substr_replace_null_offset_strict.phpt'
        );
        yield 'substr_replace_null_offset_dep_type.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/substr_replace_null_offset_dep_type.phpt',
            'substr_replace_null_offset_dep_type.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
