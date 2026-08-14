<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: SplFileInfo stat/predicate excess argc (#31000). */
final class SplFileInfoStatExcessArgc31000JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'splfileinfo_stat_excess_argc_31000_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/splfileinfo_stat_excess_argc_31000_jit.phpt',
            'splfileinfo_stat_excess_argc_31000_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
