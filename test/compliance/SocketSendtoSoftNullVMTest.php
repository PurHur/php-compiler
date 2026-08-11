<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: socket_sendto(null) soft-null data/address (#30319). */
final class SocketSendtoSoftNullVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'socket_sendto_soft_null.phpt' => self::parsePHPT(
            __DIR__.'/cases/sockets/socket_sendto_soft_null.phpt',
            'socket_sendto_soft_null.phpt'
        );
        yield 'socket_sendto_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/sockets/socket_sendto_null_strict.phpt',
            'socket_sendto_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
