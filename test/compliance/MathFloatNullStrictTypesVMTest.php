<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: float math(null) TypeError under strict_types (#29782). */
final class MathFloatNullStrictTypesVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'math_float_null_strict_types.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/math_float_null_strict_types.phpt',
            'math_float_null_strict_types.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
