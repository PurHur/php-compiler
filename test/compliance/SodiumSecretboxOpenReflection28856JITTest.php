<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: sodium_crypto_secretbox_open() Reflection arity + named args (#28856).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 */
final class SodiumSecretboxOpenReflection28856JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'sodium_secretbox_open_reflection.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/sodium_secretbox_open_reflection.phpt',
            'sodium_secretbox_open_reflection.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
