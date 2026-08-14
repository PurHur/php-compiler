<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: stream_context_get_options() ArgumentCountError wording (#30785). */
final class StreamContextGetOptionsExcessArgc30785JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_stream_context_get_options_30785_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_stream_context_get_options_30785_jit.phpt',
            'excess_argc_stream_context_get_options_30785_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
