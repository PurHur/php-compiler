<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: debug_print_backtrace(null) $options under strict_types → TypeError (#31384).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 */
final class DebugPrintBacktraceNullOptionsStrict31384JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'debug_print_backtrace_null_options_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/debug_print_backtrace_null_options_strict_jit.phpt',
            'debug_print_backtrace_null_options_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
