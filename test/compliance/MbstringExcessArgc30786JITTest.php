<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: mbstring excess argc → at-most ArgumentCountError (#30786). */
final class MbstringExcessArgc30786JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_mbstring_30786_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_mbstring_30786_jit.phpt',
            'excess_argc_mbstring_30786_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
