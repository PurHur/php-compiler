<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: echo -INF is -INF (#32317).
 *
 * @group llvm
 */
final class UnaryMinusNegInf32317JITTest extends BaseTest
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
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
