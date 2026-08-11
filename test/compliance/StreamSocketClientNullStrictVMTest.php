<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: stream_socket_client(null) TypeError under strict_types (#30314). */
final class StreamSocketClientNullStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'stream_socket_client_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/stream_socket_client_null_strict.phpt',
            'stream_socket_client_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
