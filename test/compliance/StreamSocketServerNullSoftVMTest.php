<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: stream_socket_server(null) soft Deprecated+Warning+false (#30374). */
final class StreamSocketServerNullSoftVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'stream_socket_server_null_soft.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/stream_socket_server_null_soft.phpt',
            'stream_socket_server_null_soft.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
