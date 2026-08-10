<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * @group llvm
 */
/** JIT compliance for natcasesort(). */
final class NatcasesortJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'natcasesort_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/natcasesort_jit.phpt',
            'natcasesort_jit.phpt'
        );
        yield 'natcasesort_null_elements_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/natcasesort_null_elements_jit.phpt',
            'natcasesort_null_elements_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
