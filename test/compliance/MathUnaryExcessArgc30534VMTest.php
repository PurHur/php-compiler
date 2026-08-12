<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: unary math + pi() excess argc → ArgumentCountError (#30534). */
final class MathUnaryExcessArgc30534VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_math_unary_30534.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_math_unary_30534.phpt',
            'excess_argc_math_unary_30534.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
