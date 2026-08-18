<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: asort(SORT_NATURAL) matches php_natsort (#32295).
 *
 * @group llvm
 */
final class AsortNatural32295JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'asort_natural_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/asort_natural_jit.phpt',
            'asort_natural_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
