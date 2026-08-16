<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: sodium_crypto_stream_xchacha20_xor_ic() Reflection arity + named args (#27917).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 */
final class SodiumStreamXorIcReflection27917JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'sodium_stream_xor_ic_reflection.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/sodium_stream_xor_ic_reflection.phpt',
            'sodium_stream_xor_ic_reflection.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
