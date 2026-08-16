<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: doubleval() excess argc → ArgumentCountError cites doubleval() (#30688). */
final class DoublevalExcessArgc30688JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_doubleval_30688_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_doubleval_30688_jit.phpt',
            'excess_argc_doubleval_30688_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
