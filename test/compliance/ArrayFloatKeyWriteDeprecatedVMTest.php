<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance: float array key write E_DEPRECATED (#19730). */
final class ArrayFloatKeyWriteDeprecatedVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_float_key_write_deprecated.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/array_float_key_write_deprecated.phpt',
            'array_float_key_write_deprecated.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
