<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for mbstring float int params under strict_types (#13849). */
final class MbFloatIntStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'mb_float_int_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/mbstring/mb_float_int_strict.phpt',
            'mb_float_int_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
