<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT compliance for filter_input() enum key TypeError (#7204). */
final class FilterInputEnumJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'filter_input_enum_key_typeerror_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/filter_input_enum_key_typeerror_jit.phpt',
            'filter_input_enum_key_typeerror_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
