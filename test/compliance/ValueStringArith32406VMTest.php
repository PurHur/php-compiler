<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: boxed null ⊙ numeric-string (#32406).
 */
final class ValueStringArith32406VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'value_string_arith.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/value_string_arith.phpt',
            'value_string_arith.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
