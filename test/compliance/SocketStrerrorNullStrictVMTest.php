<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: socket_strerror(null) TypeError under strict_types (#30266). */
final class SocketStrerrorNullStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'socket_strerror_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/sockets/socket_strerror_null_strict.phpt',
            'socket_strerror_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
