<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: socket_get_option/set_option(null) TypeError under strict_types (#30265). */
final class SocketGetOptionNullStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'socket_get_option_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/sockets/socket_get_option_null_strict.phpt',
            'socket_get_option_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
