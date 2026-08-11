<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: socket_create_pair null/invalid domain ValueError (#30338). */
final class SocketCreatePairNullDomainVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'socket_create_pair_null_domain.phpt' => self::parsePHPT(
            __DIR__.'/cases/sockets/socket_create_pair_null_domain.phpt',
            'socket_create_pair_null_domain.phpt'
        );
        yield 'socket_create_pair_valid.phpt' => self::parsePHPT(
            __DIR__.'/cases/sockets/socket_create_pair_valid.phpt',
            'socket_create_pair_valid.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
