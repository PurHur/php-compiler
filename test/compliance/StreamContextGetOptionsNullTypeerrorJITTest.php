<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: stream_context_get_options(null) TypeError names $stream_or_context (#30418). */
final class StreamContextGetOptionsNullTypeerrorJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'stream_context_get_options_null_typeerror_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/streams/stream_context_get_options_null_typeerror_jit.phpt',
            'stream_context_get_options_null_typeerror_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh or use the 22.04-dev Docker image.'
            );
        }
    }
}
