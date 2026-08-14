<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: Generator::rewind() excess argc → ArgumentCountError (#31034). */
final class GeneratorRewindExcessArgc31034JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_generator_rewind_31034_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_generator_rewind_31034_jit.phpt',
            'excess_argc_generator_rewind_31034_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
