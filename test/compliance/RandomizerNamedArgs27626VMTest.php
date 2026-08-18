<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: Randomizer getBytes named args + Reflection (#27626).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class RandomizerNamedArgs27626VMTest extends BaseTest
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
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
