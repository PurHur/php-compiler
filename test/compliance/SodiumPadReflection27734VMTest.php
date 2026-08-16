<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: sodium_pad()/sodium_unpad() Reflection arity + named args (#27734).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class SodiumPadReflection27734VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'sodium_pad_reflection.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/sodium_pad_reflection.phpt',
            'sodium_pad_reflection.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
