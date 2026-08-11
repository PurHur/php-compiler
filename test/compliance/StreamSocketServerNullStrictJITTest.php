<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: stream_socket_server(null) TypeError under strict_types (#30374). */
final class StreamSocketServerNullStrictJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'stream_socket_server_null_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/stream_socket_server_null_strict_jit.phpt',
            'stream_socket_server_null_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
