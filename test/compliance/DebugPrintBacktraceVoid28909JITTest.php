<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: debug_print_backtrace Reflection return void (#28909).
 */
final class DebugPrintBacktraceVoid28909JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'debug_print_backtrace_void_28909.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/debug_print_backtrace_void_28909.phpt',
            'debug_print_backtrace_void_28909.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
