<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: native float bitwise via convert_to_long (#32414).
 */
final class FloatBitwise32414VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'float_bitwise_native.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/float_bitwise_native.phpt',
            'float_bitwise_native.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
