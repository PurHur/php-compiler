<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT compliance for debug_backtrace() (#1378, #1870, #1056).
 *
 * @group llvm
 * @group jit
 */
final class DebugBacktraceJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'debug_backtrace_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/debug_backtrace_jit.phpt',
            'debug_backtrace_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped(LlvmToolchain::readyFailureReason() ?? 'LLVM 9 not available');
        }
    }
}
