<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: socket_bind(null) soft-null Deprecated+Host lookup+false (#30315). */
final class SocketBindSoftNullVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'socket_bind_soft_null.phpt' => self::parsePHPT(
            __DIR__.'/cases/sockets/socket_bind_soft_null.phpt',
            'socket_bind_soft_null.phpt'
        );
        yield 'socket_bind_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/sockets/socket_bind_null_strict.phpt',
            'socket_bind_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
