<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: PHP_INT_MIN % -1 is 0 (#32285).
 *
 * @group llvm
 */
final class IntMinModNegOne32285JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'int_min_mod_neg_one.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/int_min_mod_neg_one.phpt',
            'int_min_mod_neg_one.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
