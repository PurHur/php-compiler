<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: PHP_INT_MIN % -1 is 0 (#31968 remaining zend_operators.c mod_function).
 *
 * @group llvm
 */
final class IntMinModuloNegOne31968JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'int_min_modulo_neg_one.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/int_min_modulo_neg_one.phpt',
            'int_min_modulo_neg_one.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
