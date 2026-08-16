<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: debug_print_backtrace(null) $options under strict_types → TypeError (#31384).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class DebugPrintBacktraceNullOptionsStrict31384VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'debug_print_backtrace_null_options_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/debug_print_backtrace_null_options_strict.phpt',
            'debug_print_backtrace_null_options_strict.phpt'
        );
        yield 'debug_print_backtrace_null_options_soft_dep.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/debug_print_backtrace_null_options_soft_dep.phpt',
            'debug_print_backtrace_null_options_soft_dep.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
