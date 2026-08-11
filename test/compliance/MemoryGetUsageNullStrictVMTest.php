<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: memory_get_usage/peak_usage(null) TypeError under strict_types (#30346). */
final class MemoryGetUsageNullStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'memory_get_usage_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/memory_get_usage_null_strict.phpt',
            'memory_get_usage_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
