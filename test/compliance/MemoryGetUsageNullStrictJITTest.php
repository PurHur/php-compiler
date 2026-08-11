<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: memory_get_usage/peak_usage(null) TypeError under strict_types (#30346). */
final class MemoryGetUsageNullStrictJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'memory_get_usage_null_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/memory_get_usage_null_strict_jit.phpt',
            'memory_get_usage_null_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
