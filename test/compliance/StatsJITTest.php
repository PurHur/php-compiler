<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * @group llvm
 */
/** JIT compliance for ext/stats (#5748). */
final class StatsJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'stats_standard_deviation_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/stats_standard_deviation_jit.phpt',
            'stats_standard_deviation_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
