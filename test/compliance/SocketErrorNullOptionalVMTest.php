<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: socket_clear_error/last_error(null) process errno (#30267). */
final class SocketErrorNullOptionalVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'socket_error_null_optional.phpt' => self::parsePHPT(
            __DIR__.'/cases/sockets/socket_error_null_optional.phpt',
            'socket_error_null_optional.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
