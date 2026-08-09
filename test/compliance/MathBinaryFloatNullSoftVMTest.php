<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: fdiv/fmod/hypot/atan2(null) DEP+coerce on PROFILE=8.4 (#29319, re-#24198). */
final class MathBinaryFloatNullSoftVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'math_binary_float_null_soft_forward84.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/math_binary_float_null_soft_forward84.phpt',
            'math_binary_float_null_soft_forward84.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
