<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: socket_send(null) soft-null Deprecated+Warning+false (#30321). */
final class SocketSendSoftNullVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'socket_send_soft_null.phpt' => self::parsePHPT(
            __DIR__.'/cases/sockets/socket_send_soft_null.phpt',
            'socket_send_soft_null.phpt'
        );
        yield 'socket_send_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/sockets/socket_send_null_strict.phpt',
            'socket_send_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
