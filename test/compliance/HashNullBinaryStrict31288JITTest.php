<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: hash(..., null) $binary under strict_types → TypeError (#31288). */
final class HashNullBinaryStrict31288JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'hash_null_binary_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/hash_null_binary_strict_jit.phpt',
            'hash_null_binary_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
