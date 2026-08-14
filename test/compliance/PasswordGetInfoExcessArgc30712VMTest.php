<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: password_get_info() excess argc → ArgumentCountError (#30712). */
final class PasswordGetInfoExcessArgc30712VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_password_get_info_30712.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_password_get_info_30712.phpt',
            'excess_argc_password_get_info_30712.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
