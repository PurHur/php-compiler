<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT compliance for microtime() (#2186).
 *
 * @group llvm
 * @group jit
 */
final class MicrotimeJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'microtime_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/microtime_jit.phpt',
            'microtime_jit.phpt'
        );
        yield 'microtime_named_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/microtime_named_jit.phpt',
            'microtime_named_jit.phpt'
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
