<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: stream_set_timeout null $seconds under strict_types → TypeError (#31263).
 */
final class StreamSetTimeoutNullSeconds31263JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'stream_set_timeout_null_seconds_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/stream_set_timeout_null_seconds_strict_jit.phpt',
            'stream_set_timeout_null_seconds_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
