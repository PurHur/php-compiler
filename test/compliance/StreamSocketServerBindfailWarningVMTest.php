<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: stream_socket_server non-empty bind failure Warnings (#30395). */
final class StreamSocketServerBindfailWarningVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'stream_socket_server_bindfail_warning.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/stream_socket_server_bindfail_warning.phpt',
            'stream_socket_server_bindfail_warning.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
