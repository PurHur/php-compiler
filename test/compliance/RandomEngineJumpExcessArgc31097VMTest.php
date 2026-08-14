<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: Random\Engine Xoshiro/PCG jump() excess argc ArgumentCountError (#31097). */
final class RandomEngineJumpExcessArgc31097VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'random_engine_jump_excess_argc_31097.phpt' => self::parsePHPT(
            __DIR__.'/cases/random/random_engine_jump_excess_argc_31097.phpt',
            'random_engine_jump_excess_argc_31097.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
