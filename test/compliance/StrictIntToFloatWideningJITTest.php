<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: declare(strict_types=1) allows int→float widening (#28615, Zend/zend_execute.h).
 *
 * @group llvm
 */
final class StrictIntToFloatWideningJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'strict_int_to_float_widening.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/strict_int_to_float_widening.phpt',
            'strict_int_to_float_widening.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
