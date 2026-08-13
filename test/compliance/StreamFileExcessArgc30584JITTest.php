<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: stream/file builtins excess argc → ArgumentCountError (#30584). */
final class StreamFileExcessArgc30584JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_stream_file_30584_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_stream_file_30584_jit.phpt',
            'excess_argc_stream_file_30584_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
