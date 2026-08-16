<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: sodium_bin2base64()/sodium_base642bin() Reflection arity + named args (#27853).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 */
final class SodiumBin2base64Reflection27853JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'sodium_bin2base64_reflection.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/sodium_bin2base64_reflection.phpt',
            'sodium_bin2base64_reflection.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
