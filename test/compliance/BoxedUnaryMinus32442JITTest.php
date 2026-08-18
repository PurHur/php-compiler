<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: boxed unary minus stays int for numeric strings/longs (#32442).
 *
 * @group llvm
 */
final class BoxedUnaryMinus32442JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'boxed_unary_minus.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/boxed_unary_minus.phpt',
            'boxed_unary_minus.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
