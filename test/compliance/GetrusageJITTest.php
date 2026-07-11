<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT compliance for getrusage() (#3240, #4600, #6707). */
final class GetrusageJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'getrusage_enum_typeerror_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/getrusage_enum_typeerror_jit.phpt',
            'getrusage_enum_typeerror_jit.phpt'
        );
        yield 'getrusage_type_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/getrusage_type_jit.phpt',
            'getrusage_type_jit.phpt'
        );
        yield 'getrusage_bool_type_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/getrusage_bool_type_jit.phpt',
            'getrusage_bool_type_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
