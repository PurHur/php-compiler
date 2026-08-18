<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: native bool ⊙ numeric-string (#32401).
 */
final class BoolStringArith32401VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'bool_string_arith.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/bool_string_arith.phpt',
            'bool_string_arith.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
