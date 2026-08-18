<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: Randomizer getFloat/getBytesFromString named args + Reflection (#26257).
 */
final class RandomizerNamedArgs26257JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'randomizer_getfloat_named_26257.phpt';
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
