<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: echo -INF is -INF (#32317).
 */
final class UnaryMinusNegInf32317VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'unary_minus_neg_inf.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/unary_minus_neg_inf.phpt',
            'unary_minus_neg_inf.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
