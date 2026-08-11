<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: socket_connect(null) soft-null + strict $address (#30316). */
final class SocketConnectSoftNullVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'socket_connect_soft_null.phpt' => self::parsePHPT(
            __DIR__.'/cases/sockets/socket_connect_soft_null.phpt',
            'socket_connect_soft_null.phpt'
        );
        yield 'socket_connect_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/sockets/socket_connect_null_strict.phpt',
            'socket_connect_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
