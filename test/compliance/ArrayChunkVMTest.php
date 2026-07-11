<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for array_chunk(). */
final class ArrayChunkVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_chunk_enum_length_typeerror.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_chunk_enum_length_typeerror.phpt',
            'array_chunk_enum_length_typeerror.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
