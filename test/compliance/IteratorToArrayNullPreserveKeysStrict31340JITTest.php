<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: iterator_to_array(..., null) $preserve_keys under strict_types → TypeError (#31340). */
final class IteratorToArrayNullPreserveKeysStrict31340JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'iterator_to_array_null_preserve_keys_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/iterator_to_array_null_preserve_keys_strict_jit.phpt',
            'iterator_to_array_null_preserve_keys_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
