<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: socket_create_listen(null) TypeError under strict_types (#30264). */
final class SocketCreateListenNullStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'socket_create_listen_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/sockets/socket_create_listen_null_strict.phpt',
            'socket_create_listen_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
