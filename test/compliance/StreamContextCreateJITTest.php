<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT compliance for stream_context_create() (#1377, #2457).
 *
 * @group llvm
 * @group jit
 */
final class StreamContextCreateJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'stream_context_create_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/stream_context_create_jit.phpt',
            'stream_context_create_jit.phpt'
        );
        yield 'stream_context_create_options_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/stream_context_create_options_jit.phpt',
            'stream_context_create_options_jit.phpt'
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
