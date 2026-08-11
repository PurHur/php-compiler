<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: socket_connect null/omitted port ValueError on AF_INET (#30339). */
final class SocketConnectNullPortVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'socket_connect_null_port.phpt' => self::parsePHPT(
            __DIR__.'/cases/sockets/socket_connect_null_port.phpt',
            'socket_connect_null_port.phpt'
        );
        yield 'socket_connect_omitted_port.phpt' => self::parsePHPT(
            __DIR__.'/cases/sockets/socket_connect_omitted_port.phpt',
            'socket_connect_omitted_port.phpt'
        );
        yield 'socket_connect_port_zero.phpt' => self::parsePHPT(
            __DIR__.'/cases/sockets/socket_connect_port_zero.phpt',
            'socket_connect_port_zero.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
