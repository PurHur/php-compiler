<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: Random\Engine::*::generate() excess argc ArgumentCountError (#31096). */
final class RandomEngineGenerateExcessArgc31096JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'random_engine_generate_excess_argc_31096_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/random/random_engine_generate_excess_argc_31096_jit.phpt',
            'random_engine_generate_excess_argc_31096_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
