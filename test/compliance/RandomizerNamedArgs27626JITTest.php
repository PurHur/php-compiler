<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: Randomizer getBytes named args + Reflection (#27626).
 */
final class RandomizerNamedArgs27626JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'randomizer_getbytes_named_27626.phpt';
        yield $file => self::parsePHPT(
            __DIR__.'/cases/random/'.$file,
            $file
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
