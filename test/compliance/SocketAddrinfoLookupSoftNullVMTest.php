<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: socket_addrinfo_lookup(null) soft-null + strict $host (#30337). */
final class SocketAddrinfoLookupSoftNullVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'socket_addrinfo_lookup_soft_null.phpt' => self::parsePHPT(
            __DIR__.'/cases/sockets/socket_addrinfo_lookup_soft_null.phpt',
            'socket_addrinfo_lookup_soft_null.phpt'
        );
        yield 'socket_addrinfo_lookup_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/sockets/socket_addrinfo_lookup_null_strict.phpt',
            'socket_addrinfo_lookup_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
