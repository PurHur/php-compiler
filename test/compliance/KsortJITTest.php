<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * @group llvm
 */
/** JIT compliance for ksort(). */
final class KsortJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'ksort_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/ksort_jit.phpt',
            'ksort_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
