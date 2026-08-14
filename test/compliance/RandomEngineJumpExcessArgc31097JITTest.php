<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: Random\Engine Xoshiro/PCG jump() excess argc ArgumentCountError (#31097). */
final class RandomEngineJumpExcessArgc31097JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'random_engine_jump_excess_argc_31097_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/random/random_engine_jump_excess_argc_31097_jit.phpt',
            'random_engine_jump_excess_argc_31097_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
