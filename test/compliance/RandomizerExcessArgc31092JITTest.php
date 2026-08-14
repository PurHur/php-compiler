<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: Random\Randomizer getBytes/getInt/shuffleArray/pickArrayKeys excess argc ArgumentCountError (#31092). */
final class RandomizerExcessArgc31092JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'randomizer_excess_argc_31092_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/random/randomizer_excess_argc_31092_jit.phpt',
            'randomizer_excess_argc_31092_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
