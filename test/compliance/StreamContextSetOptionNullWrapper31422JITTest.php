<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: stream_context_set_option null $wrapper_or_options soft-DEP (#31422).
 */
final class StreamContextSetOptionNullWrapper31422JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'stream_context_set_option_null_wrapper_31422.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/stream_context_set_option_null_wrapper_31422.phpt',
            'stream_context_set_option_null_wrapper_31422.phpt'
        );
        yield 'stream_context_set_option_null_wrapper_strict_31422.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/stream_context_set_option_null_wrapper_strict_31422.phpt',
            'stream_context_set_option_null_wrapper_strict_31422.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
